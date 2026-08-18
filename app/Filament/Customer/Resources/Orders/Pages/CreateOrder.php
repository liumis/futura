<?php

namespace App\Filament\Customer\Resources\Orders\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource as AdminOrderResource;
use App\Filament\Customer\Resources\Orders\OrderResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Schema;

class CreateOrder extends CreateRecord
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
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingOrderAmounts = $data['order_amounts'] ?? [];
        unset($data['order_amounts']);
        $data['amount'] = '0.00';
        $data['status'] = OrderStatus::Pending->value;
        $data['user_id'] = auth()->id();
        $data['shipping_cost'] = $data['shipping_cost'] ?? 0;

        if (Schema::hasColumn('orders', 'order_date')) {
            $data['order_date'] = now();
        }

        if (Schema::hasColumn('orders', 'name')) {
            $data['name'] = '';
        }

        if (! Schema::hasColumn('orders', 'shipping_cost')) {
            unset($data['shipping_cost']);
        }

        unset($data['tracking_number']);

        $data['package_id'] = AdminOrderResource::packageIdFromAmounts(
            is_array($this->pendingOrderAmounts) ? $this->pendingOrderAmounts : [],
        );

        return $data;
    }

    protected function afterCreate(): void
    {
        AdminOrderResource::syncOrderItemsFromAmounts(
            $this->record,
            is_array($this->pendingOrderAmounts) ? $this->pendingOrderAmounts : [],
        );
        AdminOrderResource::recalculateOrderAmount($this->record->fresh());

        $order = $this->record->fresh();
        $order->loadCount('orderItems');
        ActivityLogger::log(
            ActivityLogEvent::OrderLineItemsSynced,
            'Order #'.$order->id.' line items set ('.$order->order_items_count.' lines)',
            $order,
        );

        \App\Services\OrderNotifier::created($order);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
