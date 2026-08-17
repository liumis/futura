<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class OrderShippedMailer
{
    public static function preview(Order $order, ?string $trackingNumber = null): string
    {
        $order->loadMissing('user');
        $trackingNumber ??= $order->tracking_number;

        return self::buildBody($order, $trackingNumber);
    }

    public static function send(Order $order, ?string $trackingNumber = null, ?string $body = null): void
    {
        $order->loadMissing('user');

        $email = trim((string) ($order->user?->email ?? ''));

        if ($email === '') {
            throw new \RuntimeException('The customer has no email address.');
        }

        $trackingNumber = trim((string) ($trackingNumber ?? $order->tracking_number ?? ''));

        if ($trackingNumber === '') {
            throw new \RuntimeException('A tracking number is required.');
        }

        $body = filled($body) ? trim($body) : self::buildBody($order, $trackingNumber);

        EmailTestMode::ensureCanSend();

        Mail::raw($body, function ($message) use ($email, $order): void {
            $message->to($email);

            self::applyFrom($message);
            $message->subject('Your order #'.$order->id.' has been shipped');
        });
    }

    public static function buildBody(Order $order, ?string $trackingNumber = null): string
    {
        $trackingNumber = trim((string) ($trackingNumber ?? $order->tracking_number ?? ''));
        $customer = $order->user;
        $name = trim(implode(' ', array_filter([$customer?->name, $customer?->surname])));
        $greeting = $name !== '' ? 'Hello '.$name.',' : 'Hello,';

        $parts = [
            $greeting,
            'Your order #'.$order->id.' has been shipped.',
        ];

        if ($trackingNumber !== '') {
            $parts[] = 'Tracking number: '.$trackingNumber;
        }

        return implode("\n\n", $parts);
    }

    private static function applyFrom(mixed $message): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new \RuntimeException('You must be logged in to send order emails.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Your user account has no email address.');
        }

        $name = trim(implode(' ', array_filter([$user->name, $user->surname])));

        $message->from(
            $user->email,
            $name !== '' ? $name : (string) $user->email,
        );
    }
}
