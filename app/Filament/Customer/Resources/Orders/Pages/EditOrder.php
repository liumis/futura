<?php

namespace App\Filament\Customer\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Concerns\HandlesOrderQuickOrder;
use App\Filament\Admin\Resources\Orders\OrderResource as AdminOrderResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Customer\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;

class EditOrder extends EditRecord
{
    use HandlesOrderQuickOrder;

    protected static string $resource = OrderResource::class;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingOrderAmounts = null;

    public function content(Schema $schema): Schema
    {
        return $this->orderPageContent($schema);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $amounts = [];
        $this->record->loadMissing('orderItems');
        foreach ($this->record->orderItems as $item) {
            $amounts[(string) $item->product_id] = $item->amount;
        }
        $data['order_amounts'] = $amounts;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Order $record */
        $record = $this->record;

        if (! $record->status->isCustomerEditable() || (int) $record->user_id !== (int) auth()->id()) {
            Notification::make()
                ->title('This order can no longer be edited')
                ->warning()
                ->send();

            throw new Halt;
        }

        $this->pendingOrderAmounts = $data['order_amounts'] ?? [];
        unset($data['order_amounts'], $data['status'], $data['tracking_number']);
        $data['user_id'] = auth()->id();
        $data['package_id'] = AdminOrderResource::packageIdFromAmounts(
            is_array($this->pendingOrderAmounts) ? $this->pendingOrderAmounts : [],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->pendingOrderAmounts !== null) {
            AdminOrderResource::syncOrderItemsFromAmounts(
                $this->record,
                $this->pendingOrderAmounts,
            );
            AdminOrderResource::recalculateOrderAmount($this->record->fresh());
            $this->pendingOrderAmounts = null;
        }
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            $this->quickOrderAction(),
            $this->expandOrderCartAction(),
            Action::make('submitOrder')
                ->label('Submit order')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Submit this order?')
                ->modalDescription('The order will be sent for review. Staff with Customer orders notifications will be notified.')
                ->visible(fn (): bool => $this->record?->status === OrderStatus::Draft)
                ->action(function (): void {
                    $this->submitDraftOrder();
                }),
            ...parent::buildHeaderActions(),
        ];
    }

    protected function submitDraftOrder(): void
    {
        /** @var Order $record */
        $record = $this->record;

        if ($record->status !== OrderStatus::Draft || (int) $record->user_id !== (int) auth()->id()) {
            Notification::make()
                ->title('This order cannot be submitted')
                ->warning()
                ->send();

            return;
        }

        // Persist any unsaved line edits first when possible.
        if (method_exists($this, 'save')) {
            try {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            } catch (Halt) {
                return;
            }
        }

        $record->refresh();
        $record->update(['status' => OrderStatus::Pending]);

        Notification::make()
            ->title('Order submitted')
            ->body('Order #'.$record->id.' is now pending review.')
            ->success()
            ->send();

        $this->redirect($this->getResource()::getUrl('index'));
    }
}
