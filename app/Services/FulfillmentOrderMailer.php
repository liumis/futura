<?php

namespace App\Services;

use App\Models\MailTemplate;
use App\Models\Order;
use App\Models\ShippingSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class FulfillmentOrderMailer
{
    public static function settings(): ShippingSetting
    {
        return ShippingSetting::defaultProvider();
    }

    /**
     * @param  array<string|int, int>  $amounts
     */
    public static function preview(Order $order, array $amounts = [], ?User $customer = null): string
    {
        $order->loadMissing(['orderItems.product.color.collection', 'user']);
        $customer ??= $order->user;

        if ($amounts === []) {
            $amounts = $order->orderItems
                ->filter(fn ($item): bool => (int) $item->amount > 0)
                ->mapWithKeys(fn ($item): array => [(int) $item->product_id => (int) $item->amount])
                ->all();
        }

        return self::buildBody($order->id, $amounts, $customer);
    }

    public static function send(Order $order, ?string $body = null, array $amounts = []): void
    {
        $settings = self::settings();
        $email = trim((string) ($settings->fulfillment_warehouse_email ?? ''));

        if ($email === '') {
            throw new \RuntimeException('Fulfillment warehouse email is not set on the default shipping provider.');
        }

        $order->loadMissing(['orderItems.product.color.collection', 'user']);

        if ($amounts === []) {
            $amounts = $order->orderItems
                ->filter(fn ($item): bool => (int) $item->amount > 0)
                ->mapWithKeys(fn ($item): array => [(int) $item->product_id => (int) $item->amount])
                ->all();
        }

        $body = filled($body) ? trim($body) : self::buildBody($order->id, $amounts, $order->user);
        $body = self::finalizeBodyForOrder($body, $order->id);

        EmailTestMode::ensureCanSend();

        Mail::raw($body, function ($message) use ($settings, $email, $order): void {
            $message->to($email);

            self::applyFrom($message, $settings->fulfillmentMailTemplate);
            $message->subject(self::resolveSubject($settings->fulfillmentMailTemplate, $order->id));
        });

        Order::query()
            ->whereKey($order->id)
            ->update(['warehouse_email_sent_at' => now()]);
    }

    /**
     * @param  array<string|int, int>  $amounts
     */
    public static function buildBody(int $orderId, array $amounts, ?User $customer = null): string
    {
        $settings = self::settings();
        $templateText = trim((string) ($settings->fulfillmentMailTemplate?->text ?? ''));
        $customerBlock = self::formatCustomerBlock($customer);
        $itemsList = WarehouseOrderMailer::formatItemsList($amounts);

        $parts = array_values(array_filter([
            str_replace('{order_id}', self::orderReference($orderId), $templateText),
            $customerBlock,
            $itemsList,
        ], fn (string $part): bool => trim($part) !== ''));

        if ($parts === []) {
            return 'Order #'.self::orderReference($orderId);
        }

        return implode("\n\n", $parts);
    }

    private static function orderReference(int $orderId): string
    {
        return $orderId > 0 ? (string) $orderId : '(new)';
    }

    private static function finalizeBodyForOrder(string $body, int $orderId): string
    {
        $reference = self::orderReference($orderId);

        return str_replace(['(new)', '#0', '{order_id}'], $reference, $body);
    }

    private static function resolveSubject(?MailTemplate $template, int $orderId): string
    {
        $subject = trim((string) ($template?->subject ?? ''));

        if ($subject === '') {
            return 'Order #'.self::orderReference($orderId);
        }

        return str_replace('{order_id}', self::orderReference($orderId), $subject);
    }

    private static function applyFrom(mixed $message, ?MailTemplate $template): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new \RuntimeException('You must be logged in to send fulfillment order emails.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Your user account has no email address.');
        }

        $userName = self::senderDisplayName($user);
        $templateFromName = trim((string) ($template?->from_name ?? ''));

        $fromName = $templateFromName !== ''
            ? $templateFromName.' | '.$userName
            : $userName;

        $message->from($user->email, $fromName);
    }

    private static function senderDisplayName(User $user): string
    {
        $name = trim(implode(' ', array_filter([$user->name, $user->surname])));

        if ($name !== '') {
            return $name;
        }

        return (string) $user->email;
    }

    private static function formatCustomerBlock(?User $customer): string
    {
        if ($customer === null) {
            return '';
        }

        $lines = [];

        if (filled($customer->company_name)) {
            $lines[] = 'Company: '.$customer->company_name;
        }

        $person = trim(implode(' ', array_filter([$customer->name, $customer->surname])));

        if ($person !== '') {
            $lines[] = 'Customer: '.$person;
        }

        if (filled($customer->email)) {
            $lines[] = 'Email: '.$customer->email;
        }

        if ($lines === []) {
            return '';
        }

        return "Customer details:\n".implode("\n", $lines);
    }
}
