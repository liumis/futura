<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Concerns\HandlesOrderQuickOrder;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FulfillmentOrderMailer;
use App\Services\OrderNotifier;
use App\Services\OrderStockAllocator;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as SchemaFacade;

class CreateOrder extends CreateRecord
{
    use HandlesOrderQuickOrder;

    protected static string $resource = OrderResource::class;

    /**
     * @var array<string|int, mixed>|null
     */
    protected ?array $pendingOrderAmounts = null;

    protected bool $sendEmailAfterCreate = false;

    protected bool $saveAsDraft = false;

    protected ?string $pendingEmailBody = null;

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

    public function confirmOrderApprovalAction(): Action
    {
        return Action::make('confirmOrderApproval')
            ->modalHeading('Confirm order')
            ->modalDescription('Review the fulfillment email and choose how to confirm the order.')
            ->schema([
                Forms\Components\Textarea::make('email_body')
                    ->label('Email preview')
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
            Action::make('create')
                ->label(__('filament-panels::resources/pages/create-record.form.actions.create.label'))
                ->action(function (): void {
                    $this->saveAsDraft = false;
                    $this->form->validate();

                    if ($this->isApprovedStatus()) {
                        $this->replaceMountedAction('confirmOrderApproval');

                        return;
                    }

                    $this->sendEmailAfterCreate = false;
                    $this->pendingEmailBody = null;
                    $this->create();
                })
                ->keyBindings(['mod+s']),
            Action::make('saveAsDraft')
                ->label('Save as draft')
                ->color('gray')
                ->action(function (): void {
                    $this->saveAsDraft = true;
                    $this->sendEmailAfterCreate = false;
                    $this->pendingEmailBody = null;
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
        $this->pendingOrderAmounts = is_array($this->data['order_amounts'] ?? null)
            ? $this->data['order_amounts']
            : ($data['order_amounts'] ?? []);
        unset($data['order_amounts']);
        $data['amount'] = '0.00';

        if ($this->saveAsDraft) {
            $data['status'] = OrderStatus::Draft->value;
        } elseif (auth()->user()?->hasRole('customer')) {
            $data['status'] = OrderStatus::Pending->value;
            $data['user_id'] = auth()->id();
        }

        if (SchemaFacade::hasColumn('orders', 'order_date')) {
            $data['order_date'] = now();
        }

        if (SchemaFacade::hasColumn('orders', 'name')) {
            $data['name'] = '';
        }

        if (! SchemaFacade::hasColumn('orders', 'shipping_cost')) {
            unset($data['shipping_cost']);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        OrderResource::syncOrderItemsFromAmounts(
            $this->record,
            is_array($this->pendingOrderAmounts) ? $this->pendingOrderAmounts : [],
        );
        OrderStockAllocator::applyCreated($this->record->fresh(['orderItems']));
        OrderResource::recalculateOrderAmount($this->record->fresh());

        $order = $this->record->fresh();
        $order->loadCount('orderItems');
        ActivityLogger::log(
            ActivityLogEvent::OrderLineItemsSynced,
            'Order #'.$order->id.' line items set ('.$order->order_items_count.' lines)',
            $order,
        );

        OrderNotifier::created($order);

        $this->saveAsDraft = false;

        if (! $this->sendEmailAfterCreate) {
            $this->pendingEmailBody = null;

            return;
        }

        try {
            FulfillmentOrderMailer::send(
                $order->load(['orderItems.product.color.collection', 'user']),
                $this->pendingEmailBody,
                OrderResource::normalizeAmounts(
                    is_array($this->pendingOrderAmounts) ? $this->pendingOrderAmounts : [],
                ),
            );

            Notification::make()
                ->title('Email sent to fulfillment warehouse')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Order created, but email was not sent')
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
        $amounts = OrderResource::normalizeAmounts(
            is_array($this->data['order_amounts'] ?? null)
                ? $this->data['order_amounts']
                : (is_array($data['order_amounts'] ?? null) ? $data['order_amounts'] : []),
        );
        $customer = User::query()->find($data['user_id'] ?? null);

        return FulfillmentOrderMailer::buildBody(0, $amounts, $customer);
    }

    protected function isApprovedStatus(): bool
    {
        $status = $this->form->getRawState()['status'] ?? OrderStatus::Placed->value;

        return OrderResource::isApprovedStatus($status);
    }
}
