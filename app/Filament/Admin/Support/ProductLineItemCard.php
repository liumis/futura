<?php

namespace App\Filament\Admin\Support;

use App\Models\Product;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class ProductLineItemCard
{
    public static function make(
        Product $product,
        string $amountField,
        ?array $upcoming = null,
        ?string $collectionFilterField = null,
        bool $recalculateCosts = false,
        ?string $costField = null,
    ): Group {
        $amountInputAttributes = [
            'x-init' => '$nextTick(() => { const card = $el.closest(\'.fi-order-product-card\'); if (card) { card.classList.toggle(\'fi-order-product-card--empty\', ! (parseInt($el.value || 0, 10) > 0)); } })',
            'x-on:input' => 'const card = $el.closest(\'.fi-order-product-card\'); if (card) { card.classList.toggle(\'fi-order-product-card--empty\', ! (parseInt($event.target.value || 0, 10) > 0)); }',
            'x-on:change' => 'const card = $el.closest(\'.fi-order-product-card\'); if (card) { card.classList.toggle(\'fi-order-product-card--empty\', ! (parseInt($event.target.value || 0, 10) > 0)); }',
        ];

        if (str_starts_with($amountField, 'order_amounts.')) {
            $amountInputAttributes['data-order-amount-input'] = (string) $product->id;
        }

        if ($recalculateCosts) {
            $amountInputAttributes['data-cargo-product-amount'] = (string) $product->id;
            $amountInputAttributes['x-on:input'] .= '; $dispatch(\'cargo-cost-recalculate\')';
            $amountInputAttributes['x-on:change'] .= '; $dispatch(\'cargo-cost-recalculate\')';
        }

        $amountInput = Forms\Components\TextInput::make($amountField)
            ->label('Amount')
            ->numeric()
            ->integer()
            ->minValue(0)
            ->default(0)
            ->step(1)
            ->live()
            ->extraInputAttributes($amountInputAttributes);

        $inputs = [$amountInput];

        if ($costField !== null) {
            $costInputAttributes = [];

            if ($recalculateCosts) {
                $costInputAttributes = [
                    'data-cargo-product-cost' => (string) $product->id,
                    'x-on:input' => '$dispatch(\'cargo-cost-recalculate\')',
                    'x-on:change' => '$dispatch(\'cargo-cost-recalculate\')',
                ];
            }

            $inputs = [
                Grid::make(2)
                    ->schema([
                        $amountInput,
                        Forms\Components\TextInput::make($costField)
                            ->label('Cost')
                            ->numeric()
                            ->minValue(0)
                            ->default((float) $product->default_cost)
                            ->afterStateHydrated(function (Forms\Components\TextInput $component, $state) use ($product): void {
                                if ($state === null || $state === '') {
                                    $component->state((float) $product->default_cost);
                                }
                            })
                            ->step(0.01)
                            ->prefix(Money::prefix())
                            ->live()
                            ->visibleOn('edit')
                            ->extraInputAttributes($costInputAttributes),
                    ]),
            ];
        }

        return Group::make([
            Placeholder::make('product_image_'.$product->id.'_'.$amountField)
                ->hiddenLabel()
                ->content(new HtmlString(self::thumbHtml($product))),

            Placeholder::make('product_details_'.$product->id.'_'.$amountField)
                ->hiddenLabel()
                ->content(new HtmlString(self::detailsHtml($product, $upcoming))),

            ...$inputs,
        ])
            ->extraAttributes(function (Get $get) use ($amountField): array {
                $amount = (int) ($get($amountField) ?? 0);
                $class = 'fi-order-product-card';

                if ($amount <= 0) {
                    $class .= ' fi-order-product-card--empty';
                }

                return ['class' => $class];
            })
            ->hidden(function (Get $get) use ($amountField, $collectionFilterField): bool {
                if ($collectionFilterField === null) {
                    return false;
                }

                $filter = $get($collectionFilterField) ?? 'all';

                if ($filter === 'all') {
                    return false;
                }

                $amount = (int) ($get($amountField) ?? 0);

                return match ($filter) {
                    'ordered' => $amount <= 0,
                    'unordered' => $amount > 0,
                    default => false,
                };
            });
    }

    public static function thumbHtml(Product $product): string
    {
        if ($product->isCatalog()) {
            $label = e($product->name ?: ($product->product_code ?? 'Catalog'));

            return sprintf(
                '<div class="fi-order-product-swatch fi-order-product-swatch--empty" role="img" aria-label="%s"></div>',
                $label,
            );
        }

        $imageUrl = $product->color?->imageUrl();
        $label = e(trim(($product->color?->color_name ?? 'Color').' '.($product->color?->color_code ?? '')));

        if (filled($imageUrl)) {
            return sprintf(
                '<div class="fi-order-product-swatch" style="background-image: url(\'%s\');" role="img" aria-label="%s"></div>',
                e($imageUrl),
                $label,
            );
        }

        return '<div class="fi-order-product-swatch fi-order-product-swatch--empty" aria-hidden="true"></div>';
    }

    public static function detailsHtml(Product $product, ?array $upcoming = null): string
    {
        $stock = (int) $product->current_amount;
        $html = '<div class="fi-order-product-details">';

        if ($product->isCatalog()) {
            $name = e($product->name ?: '—');
            $code = e($product->product_code ?? '—');
            $html .= '<div class="fi-order-product-name">'.$name.' <span class="fi-order-product-code">'.$code.'</span></div>';
            $html .= '<div class="fi-order-product-meta"><span>Stock: '.$stock.'</span></div>';
        } else {
            $colorName = e($product->color?->color_name ?? '—');
            $colorCode = e($product->color?->color_code ?? '—');
            $meters = e($product->name);
            $html .= '<div class="fi-order-product-name">'.$colorName.' <span class="fi-order-product-code">'.$colorCode.'</span></div>';
            $html .= '<div class="fi-order-product-meta"><span>'.$meters.'m</span><span>Stock: '.$stock.'</span></div>';
        }

        if ($upcoming !== null) {
            $estimated = $upcoming['estimated_arrival'] ?? null;
            $date = filled($estimated)
                ? e(Carbon::parse((string) $estimated)->format('Y-m-d'))
                : '—';

            $html .= '<div class="fi-order-product-upcoming">Upcoming: '.(int) $upcoming['amount'].' · Est. '.$date.'</div>';
        }

        $html .= '</div>';

        return $html;
    }
}
