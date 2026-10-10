<?php

namespace App\Filament\Admin\Resources\Cargos\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Filament\Admin\Resources\Cargos\Concerns\HandlesCargoQuickOrder;
use App\Services\ActivityLogger;
use App\Services\WarehouseOrderMailer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;

class CreateCargo extends CreateRecord
{
    use HandlesCargoQuickOrder;

    protected static string $resource = CargoResource::class;

    protected static bool $canCreateAnother = false;

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        $supplierId = (int) request()->query('supplier_id');
        $autoOrder = request()->boolean('auto_order');

        if ($supplierId <= 0 && $autoOrder) {
            $supplierId = (int) (CargoResource::defaultSupplierId() ?? 0);
        }

        if ($supplierId <= 0 && ! $autoOrder) {
            $this->callHook('afterFill');

            return;
        }

        $data = $this->form->getRawState();

        if ($supplierId > 0) {
            $data['supplier_id'] = $supplierId;
        }

        if ($autoOrder && $supplierId > 0) {
            $amounts = CargoResource::lowStockDefaultOrderAmounts($supplierId);
            $data['cargo_amounts'] = $amounts;

            if ($amounts !== []) {
                $this->orderCartOpen = true;
            }
        }

        $this->form->fill($data);

        $this->callHook('afterFill');

        if ($autoOrder) {
            $count = count($data['cargo_amounts'] ?? []);

            Notification::make()
                ->title($count > 0 ? 'Low stock order prefilled' : 'Nothing to auto-order')
                ->body($count > 0
                    ? "Set default order on {$count} low-stock item(s)."
                    : 'No products are below recommended stock for this supplier.')
                ->{$count > 0 ? 'success' : 'warning'}()
                ->send();
        }
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->quickOrderAction(),
            $this->autoOrderAction(),
            $this->expandOrderCartAction(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $this->cargoPageContent($schema);
    }

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
