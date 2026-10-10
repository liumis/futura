<?php

namespace App\Filament\Customer\Resources\Orders\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Concerns\HandlesOrderQuickOrder;
use App\Filament\Admin\Resources\Orders\OrderResource as AdminOrderResource;
use App\Filament\Customer\Resources\Orders\OrderResource;
use App\Services\ActivityLogger;
use App\Services\OrderNotifier;
use App\Services\OrderStockAllocator;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as SchemaFacade;

class CreateOrder extends CreateRecord
{
    use HandlesOrderQuickOrder;

    protected static string $resource = OrderResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingOrderAmounts = null;

    protected bool $saveAsDraft = false;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->quickOrderAction(),
            $this->expandOrderCartAction(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $this->orderPageContent($schema);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Submit order')
                ->action(function (): void {
                    $this->saveAsDraft = false;
                    $this->create();
                })
                ->keyBindings(['mod+s']),
            Action::make('saveAsDraft')
                ->label('Save as draft')
                ->color('gray')
                ->action(function (): void {
                    $this->saveAsDraft = true;
                    $this->create();
                }),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingOrderAmounts = $data['order_amounts'] ?? [];
        unset($data['order_amounts']);
        $data['amount'] = '0.00';
        $data['status'] = $this->saveAsDraft
            ? OrderStatus::Draft->value
            : OrderStatus::Pending->value;
        $data['user_id'] = auth()->id();
        $data['shipping_cost'] = $data['shipping_cost'] ?? 0;

        if (SchemaFacade::hasColumn('orders', 'order_date')) {
            $data['order_date'] = now();
        }

        if (SchemaFacade::hasColumn('orders', 'name')) {
            $data['name'] = '';
        }

        if (! SchemaFacade::hasColumn('orders', 'shipping_cost')) {
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
        OrderStockAllocator::applyCreated($this->record->fresh(['orderItems']));
        AdminOrderResource::recalculateOrderAmount($this->record->fresh());

        $order = $this->record->fresh();
        $order->loadCount('orderItems');
        ActivityLogger::log(
            ActivityLogEvent::OrderLineItemsSynced,
            'Order #'.$order->id.' line items set ('.$order->order_items_count.' lines)',
            $order,
        );

        OrderNotifier::created($order);
        $this->saveAsDraft = false;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->record?->status === OrderStatus::Draft
            ? 'Order saved as draft'
            : 'Order submitted';
    }
}
