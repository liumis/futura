<?php

namespace App\Filament\Admin\Resources\Cargos\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Services\ActivityLogger;
use App\Services\WarehouseOrderMailer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateCargo extends CreateRecord
{
    protected static string $resource = CargoResource::class;

    protected static bool $canCreateAnother = false;

    protected bool $sendEmailAfterCreate = false;

    protected ?string $pendingEmailBody = null;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingCargoAmounts = null;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingCargoCosts = null;

    public function confirmWarehouseOrderAction(): Action
    {
        return Action::make('confirmWarehouseOrder')
            ->modalHeading('Confirm warehouse order')
            ->modalDescription('Review and edit the supplier email, then choose how to save the order.')
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
                $this->sendEmailAfterCreate = (bool) ($arguments['sendEmail'] ?? false);
                $this->create();
            });
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('reviewOrder')
                ->label(__('filament-panels::resources/pages/create-record.form.actions.create.label'))
                ->action(function (): void {
                    $this->form->validate();

                    if ($this->isOrderedStatus()) {
                        $this->replaceMountedAction('confirmWarehouseOrder');

                        return;
                    }

                    $this->sendEmailAfterCreate = false;
                    $this->pendingEmailBody = null;
                    $this->create();
                })
                ->keyBindings(['mod+s']),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingCargoAmounts = $data['cargo_amounts'] ?? [];
        $this->pendingCargoCosts = $data['cargo_costs'] ?? [];

        unset($data['cargo_amounts'], $data['cargo_costs'], $data['cargo_line_filters']);

        return $data;
    }

    protected function afterCreate(): void
    {
        CargoResource::syncCargoItemsFromAmounts(
            $this->record,
            is_array($this->pendingCargoAmounts) ? $this->pendingCargoAmounts : [],
            is_array($this->pendingCargoCosts) ? $this->pendingCargoCosts : [],
        );

        $cargo = $this->record->fresh();
        $cargo->loadCount('cargoItems');
        ActivityLogger::log(
            ActivityLogEvent::CargoLineItemsSynced,
            'Warehouse order #'.$cargo->id.' line items set ('.$cargo->cargo_items_count.' lines)',
            $cargo,
        );

        if (! $this->sendEmailAfterCreate) {
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
                ->title('Warehouse order created, but email was not sent')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        } finally {
            $this->sendEmailAfterCreate = false;
            $this->pendingEmailBody = null;
        }
    }

    protected function buildEmailPreviewFromForm(): string
    {
        $data = $this->form->getRawState();

        return WarehouseOrderMailer::preview(
            isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            CargoResource::filterAmountsForSupplier(
                isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                is_array($data['cargo_amounts'] ?? null) ? $data['cargo_amounts'] : [],
            ),
        );
    }

    protected function isOrderedStatus(): bool
    {
        $status = $this->form->getRawState()['status'] ?? CargoStatus::Draft->value;

        return CargoResource::isOrderedStatus($status);
    }
}
