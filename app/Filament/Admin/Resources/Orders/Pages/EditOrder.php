<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Enums\ActivityLogEvent;
use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\Concerns\HandlesOrderQuickOrder;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FulfillmentOrderMailer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class EditOrder extends EditRecord
{
    use HandlesOrderQuickOrder;

    protected static string $resource = OrderResource::class;

    protected bool $sendEmailAfterSave = false;

    protected ?string $pendingEmailBody = null;

    /**
     * @return array<Action | ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            $this->quickOrderAction(),
            $this->expandOrderCartAction(),
            Action::make('summary')
                ->label('Summary')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->modalHeading(fn (): string => 'Order #'.$this->record->id.' summary')
                ->modalContent(function (): Htmlable {
                    $order = $this->summaryOrderFromForm();

                    return new HtmlString(
                        view('filament.admin.components.order-items-quick-view', [
                            'order' => $order,
                        ])->render()
                    );
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('5xl'),
            Action::make('resend')
                ->label('Resend')
                ->color('gray')
                ->modalHeading('Resend fulfillment order email?')
                ->modalDescription('Edit the email text if needed, then resend it to the fulfillment warehouse.')
                ->schema([
                    Forms\Components\Textarea::make('email_body')
                        ->label('Email preview')
                        ->rows(14)
                        ->columnSpanFull(),
                ])
                ->fillForm(fn (): array => [
                    'email_body' => $this->buildEmailPreviewFromForm(),
                ])
                ->modalSubmitActionLabel('Resend')
                ->visible(fn (): bool => $this->record?->status === OrderStatus::Approved)
                ->action(function (array $data): void {
                    $this->resendEmail($data['email_body'] ?? null);
                }),
            ...parent::buildHeaderActions(),
        ];
    }

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
        if (auth()->user()?->hasRole('customer')) {
            unset($data['status']);
            $data['user_id'] = auth()->id();
        }

        return $data;
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
                $this->sendEmailAfterSave = (bool) ($arguments['sendEmail'] ?? false);
                $this->save();
            });
    }

    /**
     * Build a transient order snapshot for the summary modal, including unsaved form quantities/shipping.
     */
    protected function summaryOrderFromForm(): Order
    {
        $order = $this->record->fresh([
            'orderItems.product.productType',
            'orderItems.product.color.collection',
            'user',
            'package',
            'invoices',
        ]);

        $data = $this->form->getRawState();
        $amounts = OrderResource::normalizeAmounts(
            is_array($this->data['order_amounts'] ?? null)
                ? $this->data['order_amounts']
                : (is_array($data['order_amounts'] ?? null) ? $data['order_amounts'] : []),
        );

        if ($amounts !== []) {
            $products = Product::query()
                ->with(['productType', 'color.collection'])
                ->whereIn('id', array_keys($amounts))
                ->get()
                ->keyBy('id');

            $items = collect($amounts)->map(function (int $amount, int|string $productId) use ($products) {
                $productId = (int) $productId;
                $item = new OrderItem([
                    'product_id' => $productId,
                    'amount' => $amount,
                ]);
                $item->setRelation('product', $products->get($productId));

                return $item;
            })->values();

            $order->setRelation('orderItems', $items);
        }

        if (array_key_exists('shipping_cost', $data)) {
            $order->shipping_cost = $data['shipping_cost'];
        }

        if (array_key_exists('tracking_number', $data)) {
            $order->tracking_number = $data['tracking_number'];
        }

        if (isset($data['status'])) {
            $order->status = $data['status'] instanceof OrderStatus
                ? $data['status']
                : OrderStatus::tryFrom((string) $data['status']) ?? $order->status;
        }

        if (isset($data['user_id']) && (int) $data['user_id'] !== (int) $order->user_id) {
            $order->user_id = (int) $data['user_id'];
            $order->setRelation('user', User::query()->find($data['user_id']));
        }

        return $order;
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->action(function (): void {
                    $this->form->validate();

                    if ($this->isTransitioningToApproved()) {
                        $this->replaceMountedAction('confirmOrderApproval');

                        return;
                    }

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

                if ($this->isTransitioningToApproved()) {
                    $this->replaceMountedAction('confirmOrderApproval');

                    return;
                }

                $this->save();
            })
            ->keyBindings(['mod+s']);
    }

    public function resendEmail(?string $emailBody = null): void
    {
        try {
            FulfillmentOrderMailer::send(
                $this->record->fresh(['orderItems.product.color.collection', 'user']),
                $emailBody,
                OrderResource::normalizeAmounts(
                    is_array($this->data['order_amounts'] ?? null)
                        ? $this->data['order_amounts']
                        : [],
                ),
            );

            Notification::make()
                ->title('Email sent to fulfillment warehouse')
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
        $data = $this->form->getRawState();
        $amounts = OrderResource::normalizeAmounts(
            is_array($this->data['order_amounts'] ?? null)
                ? $this->data['order_amounts']
                : (is_array($data['order_amounts'] ?? null) ? $data['order_amounts'] : []),
        );
        $customer = User::query()->find($data['user_id'] ?? $this->record->user_id);

        return FulfillmentOrderMailer::preview($this->record, $amounts, $customer);
    }

    protected function isTransitioningToApproved(): bool
    {
        $data = $this->form->getRawState();
        $newStatus = $data['status'] ?? $this->record->status;

        return OrderResource::isTransitionToApproved($newStatus, $this->record->status);
    }

    protected function afterSave(): void
    {
        $amounts = is_array($this->data['order_amounts'] ?? null)
            ? $this->data['order_amounts']
            : ($this->form->getRawState()['order_amounts'] ?? []);
        OrderResource::syncOrderItemsFromAmounts($this->record, is_array($amounts) ? $amounts : []);
        OrderResource::recalculateOrderAmount($this->record->fresh());

        $order = $this->record->fresh();
        $order->loadCount('orderItems');
        ActivityLogger::log(
            ActivityLogEvent::OrderLineItemsSynced,
            'Order #'.$order->id.' line items updated ('.$order->order_items_count.' lines)',
            $order,
        );

        if (! $this->sendEmailAfterSave) {
            $this->pendingEmailBody = null;

            return;
        }

        try {
            FulfillmentOrderMailer::send(
                $order->load(['orderItems.product.color.collection', 'user']),
                $this->pendingEmailBody,
                OrderResource::normalizeAmounts(is_array($amounts) ? $amounts : []),
            );

            Notification::make()
                ->title('Email sent to fulfillment warehouse')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Order saved, but email was not sent')
                ->body($exception->getMessage())
                ->warning()
                ->send();
        } finally {
            $this->sendEmailAfterSave = false;
            $this->pendingEmailBody = null;
        }
    }
}
