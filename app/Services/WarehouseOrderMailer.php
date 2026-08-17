<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class WarehouseOrderMailer
{
    /**
     * @param  array<string|int, int>  $amounts
     */
    public static function buildBody(Supplier $supplier, array $amounts): string
    {
        $supplier->loadMissing('mailTemplate');

        $templateText = trim((string) ($supplier->mailTemplate?->text ?? ''));
        $itemsList = self::formatItemsList($amounts);

        if ($templateText === '') {
            return $itemsList;
        }

        if ($itemsList === '') {
            return $templateText;
        }

        return $templateText."\n\n".$itemsList;
    }

    public static function preview(?int $supplierId, array $amounts): string
    {
        $supplier = Supplier::query()
            ->with('mailTemplate')
            ->find($supplierId);

        if ($supplier === null) {
            return 'No supplier selected.';
        }

        return self::buildBody($supplier, $amounts);
    }

    public static function send(Cargo $cargo, ?string $body = null): void
    {
        $cargo->loadMissing(['supplier.mailTemplate', 'cargoItems']);

        if ($cargo->supplier === null) {
            throw new \RuntimeException('No supplier selected for this warehouse order.');
        }

        $amounts = [];

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount > 0) {
                $amounts[$item->product_id] = $item->amount;
            }
        }

        self::sendForSupplier($cargo->supplier, $amounts, $cargo->id, $body);
    }

    /**
     * @param  array<string|int, int>  $amounts
     */
    public static function sendForSupplier(Supplier $supplier, array $amounts, int $orderId, ?string $body = null): void
    {
        $supplier->loadMissing('mailTemplate');

        if (blank($supplier->email)) {
            throw new \RuntimeException('The selected supplier has no email address.');
        }

        $body = filled($body) ? trim($body) : self::buildBody($supplier, $amounts);

        EmailTestMode::ensureCanSend();

        Mail::raw($body, function ($message) use ($supplier, $orderId): void {
            $message->to($supplier->email);

            self::applyFrom($message, $supplier);
            $message->subject(self::resolveSubject($supplier, $orderId));
        });

        Cargo::query()
            ->whereKey($orderId)
            ->update(['email_sent_at' => now()]);
    }

    private static function resolveSubject(Supplier $supplier, int $orderId): string
    {
        $subject = trim((string) ($supplier->mailTemplate?->subject ?? ''));

        if ($subject === '') {
            return 'Warehouse order #'.$orderId;
        }

        return str_replace('{order_id}', (string) $orderId, $subject);
    }

    private static function applyFrom(mixed $message, Supplier $supplier): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new \RuntimeException('You must be logged in to send warehouse order emails.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Your user account has no email address.');
        }

        $userName = self::senderDisplayName($user);
        $templateFromName = trim((string) ($supplier->mailTemplate?->from_name ?? ''));

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

    /**
     * @param  array<string|int, int>  $amounts
     */
    public static function formatItemsList(array $amounts): string
    {
        $productIds = collect($amounts)
            ->filter(fn ($amount): bool => (int) $amount > 0)
            ->keys()
            ->map(fn ($productId): int => (int) $productId)
            ->all();

        if ($productIds === []) {
            return '';
        }

        $products = Product::query()
            ->with(['productType', 'color.collection'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $lines = [];

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;

            if ($amount <= 0) {
                continue;
            }

            $product = $products->get((int) $productId);

            if ($product === null) {
                continue;
            }

            if ($product->isCatalog()) {
                $lines[] = sprintf(
                    '%s: %s (%s) × %d',
                    $product->productType?->name ?? 'Catalog',
                    $product->name ?? '—',
                    $product->product_code ?? '—',
                    $amount,
                );

                continue;
            }

            $lines[] = sprintf(
                '%s: %s (%s) × %d',
                $product->color?->collection?->name ?? '—',
                $product->color?->color_name ?? '—',
                $product->color?->color_code ?? '—',
                $amount,
            );
        }

        return implode("\n", $lines);
    }
}
