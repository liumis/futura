<?php

namespace App\Filament\Customer\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource as AdminOrderResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Customer\Resources\Orders\OrderResource;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingOrderAmounts = null;

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
        /** @var \App\Models\Order $record */
        $record = $this->record;

        if ($record->status !== OrderStatus::Pending || (int) $record->user_id !== (int) auth()->id()) {
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

    protected function getHeaderActions(): array
    {
        return [];
    }
}
