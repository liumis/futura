<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Currency;
use App\Models\Invoice;
use App\Services\SharepointInvoiceUploader;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

trait ManagesInvoiceUploads
{
    /**
     * @return list<string>
     */
    public static function additionalDocumentMimeTypes(): array
    {
        return [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];
    }

    protected function invoiceCurrencyFormField(): Select
    {
        return Select::make('currency')
            ->label('Currency')
            ->options(fn (Get $get): array => Currency::selectOptions(
                is_string($get('currency') ?? null) ? (string) $get('currency') : null,
            ))
            ->default(Currency::defaultCode())
            ->required()
            ->searchable()
            ->preload()
            ->native(false)
            ->live()
            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                $this->syncInvoiceEuroConversionFields($get, $set);
            });
    }

    /**
     * @return list<\Filament\Forms\Components\Component>
     */
    protected function invoiceEuroConversionFormFields(): array
    {
        return [
            TextInput::make('currency_rate')
                ->label('EUR rate')
                ->numeric()
                ->step(0.000001)
                ->readOnly()
                ->dehydrated()
                ->helperText('Foreign units per 1 EUR (from Currencies list at save).'),

            TextInput::make('sum_inc_vat_eur')
                ->label('Total (EUR)')
                ->numeric()
                ->step(0.01)
                ->readOnly()
                ->dehydrated()
                ->prefix('€'),
        ];
    }

    protected function syncInvoiceEuroConversionFields(Get $get, Set $set): void
    {
        $code = Currency::normalize(is_string($get('currency') ?? null) ? (string) $get('currency') : null);
        $total = round((float) ($get('sum_inc_vat') ?? 0), 2);

        if ($code === 'EUR') {
            $set('currency_rate', 1);
            $set('sum_inc_vat_eur', $total);

            return;
        }

        $rate = Currency::rateFor($code);
        if ($rate === null) {
            $set('currency_rate', null);
            $set('sum_inc_vat_eur', null);

            return;
        }

        $set('currency_rate', $rate);
        $set('sum_inc_vat_eur', Currency::toEur($total, $code, $rate));
    }

    /**
     * @param  (callable(Get): float)|null  $totalResolver
     * @return list<TextInput|Checkbox>
     */
    protected function invoicePaymentFormFields(?callable $totalResolver = null): array
    {
        $resolveTotal = $totalResolver ?? fn (Get $get): float => round((float) ($get('sum_inc_vat') ?? 0), 2);

        return [
            TextInput::make('payment_term')
                ->label('Payment term')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(Invoice::DEFAULT_PAYMENT_TERM_DAYS)
                ->suffix('days')
                ->helperText('Days until payment is due. Default is '.Invoice::DEFAULT_PAYMENT_TERM_DAYS.' days.'),

            TextInput::make('paid_amount')
                ->label('Paid amount')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->default(0)
                ->live(onBlur: true)
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($resolveTotal): void {
                    if (Invoice::amountsAreFullyPaid($state, $resolveTotal($get))) {
                        $set('paid', true);
                    }
                }),

            Checkbox::make('paid')
                ->label('Paid')
                ->inline(false)
                ->live()
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($resolveTotal): void {
                    $total = $resolveTotal($get);
                    if ($state) {
                        $set('paid_amount', $total);

                        return;
                    }

                    if (Invoice::amountsAreFullyPaid($get('paid_amount'), $total)) {
                        $set('paid_amount', 0);
                    }
                }),
        ];
    }

    /**
     * @return list<string>
     */
    protected function normalizeUploadPaths(mixed $state): array
    {
        if (! is_array($state)) {
            $state = filled($state) ? [$state] : [];
        }

        return array_values(array_filter(
            $state,
            fn ($path): bool => is_string($path) && $path !== '',
        ));
    }

    /**
     * @param  array<string, string>|list<string>|mixed  $originalNames
     */
    protected function storeAdditionalDocuments(Invoice $invoice, mixed $uploads, mixed $originalNames = []): int
    {
        $paths = $this->normalizeUploadPaths($uploads);
        if ($paths === []) {
            return 0;
        }

        $before = count($invoice->additionalDocuments());
        SharepointInvoiceUploader::ingestAdditionalDocuments(
            $invoice,
            $paths,
            $this->normalizeOriginalNames($originalNames),
        );

        return max(0, count($invoice->fresh()?->additionalDocuments() ?? []) - $before);
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeOriginalNames(mixed $names): array
    {
        if (! is_array($names)) {
            return [];
        }

        $out = [];
        foreach ($names as $path => $name) {
            if (! is_string($path) || ! is_string($name)) {
                continue;
            }

            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $out[$path] = $name;
        }

        return $out;
    }
}
