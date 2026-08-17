<?php

namespace App\Filament\Admin\Resources\Cargos\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Product;
use App\Services\ActivityLogger;
use App\Models\Supplier;
use App\Services\CargoReceiver;
use App\Services\WarehouseOrderMailer;
use App\Filament\Admin\Resources\Pages\EditRecord;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

class EditCargo extends EditRecord
{
    protected static string $resource = CargoResource::class;

    protected bool $sendEmailAfterSave = false;

    protected ?string $pendingEmailBody = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
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
            ->pluck('default_cost', 'id')
            ->each(function ($defaultCost, $productId) use (&$costs): void {
                $key = (string) $productId;

                if (! array_key_exists($key, $costs)) {
                    $costs[$key] = (float) $defaultCost;
                }
            });

        $data['cargo_amounts'] = $amounts;
        $data['cargo_costs'] = $costs;

        if (blank($data['import_tax_id'] ?? null)) {
            $data['import_tax_id'] = CargoResource::defaultImportTaxId();
        }

        return $data;
    }

    /**
     * @return array<Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
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
        } catch (\Throwable $exception) {
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
        } catch (\Throwable $exception) {
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

}
