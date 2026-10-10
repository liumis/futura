<?php

namespace App\Filament\Admin\Resources\Cargos\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Filament\Admin\Resources\Cargos\Concerns\HandlesCargoQuickOrder;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\Cargo;
use App\Models\CustomsContact;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\CargoReceiver;
use App\Services\CustomsContactMailer;
use App\Services\SharepointCustomsDocumentsUploader;
use App\Services\WarehouseOrderMailer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Throwable;

class EditCargo extends EditRecord
{
    use HandlesCargoQuickOrder;

    protected static string $resource = CargoResource::class;

    protected bool $sendEmailAfterSave = false;

    protected ?string $pendingEmailBody = null;

    /** @var list<string> */
    protected array $pendingCustomsDocumentUploads = [];

    /** @var array<string, string> */
    protected array $pendingCustomsDocumentNames = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCustomsDocumentUploads = $this->normalizeUploadedFilePaths($data['customs_document_uploads'] ?? null);
        $this->pendingCustomsDocumentNames = is_array($data['customs_document_upload_names'] ?? null)
            ? array_filter($data['customs_document_upload_names'], fn ($name): bool => is_string($name) && $name !== '')
            : [];

        if (blank($data['customs_contact_id'] ?? null) && CustomsContact::countContacts() === 1) {
            $data['customs_contact_id'] = CustomsContact::defaultId();
        }

        return CargoResource::stripVirtualCargoFormFields($data);
    }

    public function confirmWarehouseOrderAction(): Action
    {
        return Action::make('confirmWarehouseOrder')
            ->modalHeading('Confirm warehouse order')
            ->modalDescription('Review and edit the supplier email, then confirm the status change to Ordered.')
            ->schema([
                Forms\Components\Textarea::make('email_body')
                    ->label('Email')
                    ->helperText('Review and edit the supplier email before sending.')
                    ->rows(14)
                    ->columnSpanFull(),
            ])
            ->fillForm(fn (): array => [
                'email_body' => $this->buildEmailPreviewFromForm(),
            ])
            ->modalSubmitActionLabel('Confirm')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('confirmAndSendEmail', arguments: ['sendEmail' => true])
                    ->label('Confirm and send email')
                    ->color('success'),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->pendingEmailBody = $data['email_body'] ?? null;
                $this->sendEmailAfterSave = (bool) ($arguments['sendEmail'] ?? false);
                $this->save();
            });
    }

    public function sendCustomsEmailAction(): Action
    {
        return Action::make('sendCustomsEmail')
            ->label('Send email to customs')
            ->modalHeading('Send customs documents email')
            ->modalDescription('Review the email, then send it to the selected customs contact. Uploaded documents are attached when available on SharePoint.')
            ->schema([
                Forms\Components\Select::make('customs_contact_id')
                    ->label('Customs contact')
                    ->options(fn (): array => CustomsContact::options())
                    ->required()
                    ->native(false)
                    ->live()
                    ->visible(fn (): bool => CustomsContact::countContacts() > 1)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $set('email_body', CustomsContactMailer::preview(
                            filled($state) ? (int) $state : null,
                            $this->record,
                        ));
                    }),

                Forms\Components\Placeholder::make('customs_contact_label')
                    ->label('Customs contact')
                    ->content(fn (): string => (string) (CustomsContact::options()[CustomsContact::defaultId()] ?? '—'))
                    ->visible(fn (): bool => CustomsContact::countContacts() === 1),

                Forms\Components\Textarea::make('email_body')
                    ->label('Email')
                    ->helperText('Uses the email template assigned to the customs contact. Placeholders: {order_id}, {tracking}, {estimated_arrival}, {supplier}, {company_name}.')
                    ->rows(12)
                    ->required()
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('attach_documents')
                    ->label('Attach customs documents')
                    ->default(true)
                    ->helperText('Downloads the SharePoint files and attaches them to the email.'),
            ])
            ->fillForm(function (): array {
                $contactId = $this->record->customs_contact_id
                    ?? CustomsContact::defaultId();

                return [
                    'customs_contact_id' => $contactId,
                    'email_body' => CustomsContactMailer::preview($contactId, $this->record),
                    'attach_documents' => true,
                ];
            })
            ->modalSubmitActionLabel('Send email')
            ->action(function (array $data): void {
                $contactId = $data['customs_contact_id']
                    ?? $this->record->customs_contact_id
                    ?? CustomsContact::defaultId();

                $contact = CustomsContact::query()
                    ->with('mailTemplate')
                    ->find($contactId);

                if ($contact === null) {
                    Notification::make()
                        ->title('No customs contact selected')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    CustomsContactMailer::send(
                        $this->record->fresh(['supplier', 'customsContact']),
                        $contact,
                        $data['email_body'] ?? null,
                        (bool) ($data['attach_documents'] ?? true),
                    );

                    Notification::make()
                        ->title('Email sent to customs')
                        ->body($contact->company_name.' · '.$contact->email)
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Email was not sent')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }

                $this->record->refresh();
                $this->fillForm();
            });
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->action(function (): void {
                    $this->form->validate();

                    if ($this->isTransitioningToOrdered()) {
                        $this->replaceMountedAction('confirmWarehouseOrder');

                        return;
                    }

                    $this->sendEmailAfterSave = false;
                    $this->pendingEmailBody = null;
                    $this->save();
                })
                ->keyBindings(['mod+s']),
            $this->getCancelFormAction(),
        ];
    }

    protected function makeHeaderSaveAction(): Action
    {
        return Action::make('save')
            ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
            ->action(function (): void {
                $this->form->validate();

                if ($this->isTransitioningToOrdered()) {
                    $this->replaceMountedAction('confirmWarehouseOrder');

                    return;
                }

                $this->sendEmailAfterSave = false;
                $this->pendingEmailBody = null;
                $this->save();
            })
            ->keyBindings(['mod+s']);
    }

    protected function isTransitioningToOrdered(): bool
    {
        $data = $this->form->getRawState();
        $newStatus = $data['status'] ?? $this->record->status;

        return CargoResource::isTransitionToOrdered($newStatus, $this->record->status);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        CargoResource::setCostContext($this->record->fresh(['cargoItems']));

        $amounts = [];
        $costs = [];
        $this->record->loadMissing('cargoItems.product');
        foreach ($this->record->cargoItems as $item) {
            $amounts[(string) $item->product_id] = $item->amount;
            $costs[(string) $item->product_id] = (float) $item->self_cost;
        }

        Product::query()
            ->with('color.collection')
            ->get()
            ->each(function (Product $product) use (&$costs): void {
                $key = (string) $product->getKey();

                if (! array_key_exists($key, $costs)) {
                    $costs[$key] = $product->collectionUnitCost();
                }
            });

        $data['cargo_amounts'] = $amounts;
        $data['cargo_costs'] = $costs;
        $data['customs_document_uploads'] = [];
        $data['customs_document_upload_names'] = [];

        if (blank($data['import_tax_id'] ?? null)) {
            $data['import_tax_id'] = CargoResource::defaultImportTaxId();
        }

        if (blank($data['customs_contact_id'] ?? null)) {
            $data['customs_contact_id'] = CustomsContact::defaultId();
        }

        return $data;
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            $this->quickOrderAction(),
            $this->autoOrderAction(),
            $this->expandOrderCartAction(),
            $this->sendCustomsEmailAction()
                ->extraAttributes(['class' => 'hidden', 'style' => 'display:none !important']),
            Action::make('resend')
                ->label('Resend')
                ->color('gray')
                ->modalHeading('Resend warehouse order email?')
                ->modalDescription('Edit the email text if needed, then resend it to the supplier.')
                ->schema([
                    Forms\Components\Textarea::make('email_body')
                        ->label('Email')
                        ->helperText('Review and edit the supplier email before sending.')
                        ->rows(14)
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (): array => [
                    'email_body' => $this->buildEmailPreviewFromForm(),
                ])
                ->modalSubmitActionLabel('Resend')
                ->visible(fn (): bool => filled($this->record?->supplier_id))
                ->action(function (array $data): void {
                    $this->resendEmail($data['email_body'] ?? null);
                }),
            ...parent::buildHeaderActions(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $this->cargoPageContent($schema);
    }

    public function resendEmail(?string $emailBody = null): void
    {
        $data = $this->form->getState();
        $supplier = Supplier::query()
            ->with('mailTemplate')
            ->find($data['supplier_id'] ?? $this->record->supplier_id);

        if ($supplier === null) {
            Notification::make()
                ->title('No supplier selected')
                ->warning()
                ->send();

            return;
        }

        $amounts = CargoResource::filterAmountsForSupplier(
            (int) ($data['supplier_id'] ?? $this->record->supplier_id),
            is_array($data['cargo_amounts'] ?? null) ? $data['cargo_amounts'] : [],
        );

        try {
            WarehouseOrderMailer::sendForSupplier($supplier, $amounts, $this->record->id, $emailBody);

            Notification::make()
                ->title('Email sent to supplier')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Email was not sent')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function buildEmailPreviewFromForm(): string
    {
        $data = $this->form->getState();

        $supplierId = isset($data['supplier_id'])
            ? (int) $data['supplier_id']
            : ($this->record->supplier_id ? (int) $this->record->supplier_id : null);

        return WarehouseOrderMailer::preview(
            $supplierId,
            CargoResource::filterAmountsForSupplier(
                $supplierId,
                is_array($data['cargo_amounts'] ?? null) ? $data['cargo_amounts'] : [],
            ),
        );
    }

    public function receiveAndImport(): void
    {
        if ($this->record->status === CargoStatus::Received) {
            Notification::make()
                ->title('Warehouse order already received')
                ->warning()
                ->send();

            return;
        }

        $data = $this->form->getState();
        $amounts = is_array($data['cargo_amounts'] ?? null) ? $data['cargo_amounts'] : [];
        $costs = is_array($data['cargo_costs'] ?? null) ? $data['cargo_costs'] : [];
        $attributes = CargoResource::stripVirtualCargoFormFields($data);

        try {
            CargoReceiver::receiveAndImport($this->record, $amounts, $costs, $attributes);
        } catch (\RuntimeException $exception) {
            Notification::make()
                ->title($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        $this->record->refresh();
        CargoResource::setCostContext($this->record->load('cargoItems'));
        $this->fillForm();

        Notification::make()
            ->title('Warehouse order received and stock imported')
            ->success()
            ->send();
    }

    protected function afterSave(): void
    {
        $state = $this->form->getState();
        $amounts = $state['cargo_amounts'] ?? [];
        $costs = $state['cargo_costs'] ?? [];
        CargoResource::syncCargoItemsFromAmounts(
            $this->record,
            is_array($amounts) ? $amounts : [],
            is_array($costs) ? $costs : [],
        );
        CargoResource::setCostContext($this->record->fresh(['cargoItems']));

        $cargo = $this->record->fresh();
        $cargo->loadCount('cargoItems');
        ActivityLogger::log(
            ActivityLogEvent::CargoLineItemsSynced,
            'Warehouse order #'.$cargo->id.' line items updated ('.$cargo->cargo_items_count.' lines)',
            $cargo,
        );

        $this->uploadPendingCustomsDocuments($cargo);

        if (! $this->sendEmailAfterSave) {
            $this->pendingEmailBody = null;

            return;
        }

        try {
            WarehouseOrderMailer::send(
                $cargo->load(['supplier.mailTemplate', 'cargoItems.product.color.collection']),
                $this->pendingEmailBody,
            );

            Notification::make()
                ->title('Email sent to supplier')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Warehouse order saved, but email was not sent')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        } finally {
            $this->sendEmailAfterSave = false;
            $this->pendingEmailBody = null;
        }
    }

    protected function uploadPendingCustomsDocuments(Cargo $cargo): void
    {
        $paths = $this->pendingCustomsDocumentUploads;
        $names = $this->pendingCustomsDocumentNames;
        $this->pendingCustomsDocumentUploads = [];
        $this->pendingCustomsDocumentNames = [];

        if ($paths === []) {
            return;
        }

        $status = CargoStatus::tryFromMixed($cargo->status);

        if ($status === null || ! $status->allowsCustomsDocuments()) {
            Notification::make()
                ->title('Customs documents not uploaded')
                ->body('Set status to Confirmed (or later) before uploading customs documents.')
                ->warning()
                ->send();

            return;
        }

        try {
            SharepointCustomsDocumentsUploader::ingestFromLocalPaths($cargo, $paths, $names);

            Notification::make()
                ->title(count($paths) === 1 ? 'Customs document uploaded' : count($paths).' customs documents uploaded')
                ->body('Stored on SharePoint.')
                ->success()
                ->send();

            $this->record->refresh();
            $this->fillForm();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Customs document upload failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return list<string>
     */
    protected function normalizeUploadedFilePaths(mixed $state): array
    {
        if (blank($state)) {
            return [];
        }

        if (is_array($state)) {
            return array_values(array_filter($state, fn ($path): bool => filled($path) && is_string($path)));
        }

        return is_string($state) ? [$state] : [];
    }
}
