@php
    $initialWithShipping = $initialWithoutShipping + $initialShippingCost;
    $initialRate = $initialImportTaxId
        ? (float) ($importTaxRates[$initialImportTaxId] ?? $importTaxRates[(string) $initialImportTaxId] ?? 0)
        : 0.0;
    $initialImportTaxes = round($initialWithShipping * $initialRate / 100, 2);
@endphp

<div
    wire:ignore
    x-data="{
        productCosts: @js($productCosts),
        importTaxRates: @js($importTaxRates),
        withoutShipping: @js($initialWithoutShipping),
        withShipping: @js($initialWithShipping),
        importTaxes: @js($initialImportTaxes),
        format(amount) {
            return '€' + Number(amount).toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        collectAmounts() {
            const amounts = { ...($wire.get('data.cargo_amounts') ?? $wire.data?.cargo_amounts ?? {}) };

            document.querySelectorAll('[data-cargo-product-amount]').forEach((input) => {
                const productId = input.dataset.cargoProductAmount;

                if (productId) {
                    amounts[productId] = input.value;
                }
            });

            return amounts;
        },
        collectCosts() {
            const costs = { ...this.productCosts };

            const formCosts = $wire.get('data.cargo_costs') ?? $wire.data?.cargo_costs ?? {};

            for (const [id, cost] of Object.entries(formCosts)) {
                if (cost !== null && cost !== '') {
                    costs[id] = parseFloat(cost) || 0;
                }
            }

            document.querySelectorAll('[data-cargo-product-cost]').forEach((input) => {
                const productId = input.dataset.cargoProductCost;

                if (productId && input.value !== '') {
                    costs[productId] = parseFloat(input.value) || 0;
                }
            });

            return costs;
        },
        getShippingCost() {
            const input = document.querySelector('[data-cargo-shipping-cost]');

            if (input && input.value !== '') {
                return parseFloat(input.value) || 0;
            }

            return parseFloat($wire.get('data.shipping_cost') ?? $wire.data?.shipping_cost ?? 0) || 0;
        },
        getImportTaxId() {
            return $wire.get('data.import_tax_id') ?? $wire.data?.import_tax_id ?? null;
        },
        update() {
            const amounts = this.collectAmounts();
            const costs = this.collectCosts();
            let total = 0;

            for (const [id, amount] of Object.entries(amounts)) {
                const qty = parseInt(amount || 0, 10);

                if (qty <= 0) {
                    continue;
                }

                const cost = costs[id] ?? costs[parseInt(id, 10)] ?? 0;
                total += qty * cost;
            }

            const shipping = this.getShippingCost();
            const taxId = this.getImportTaxId();
            const rate = taxId
                ? (this.importTaxRates[taxId] ?? this.importTaxRates[String(taxId)] ?? 0)
                : 0;

            this.withoutShipping = total;
            this.withShipping = total + shipping;
            this.importTaxes = this.withShipping * rate / 100;
        },
    }"
    x-init="
        update();
        $wire.$watch('data.cargo_amounts', () => update(), { deep: true });
        $wire.$watch('data.cargo_costs', () => update(), { deep: true });
        $wire.$watch('data.shipping_cost', () => update());
        $wire.$watch('data.import_tax_id', () => update());
    "
    x-on:cargo-cost-recalculate.window="update()"
    class="fi-cargo-cost-summary"
>
    <div class="fi-cargo-cost-summary-grid">
        <div class="fi-cargo-cost-summary-item">
            <div class="fi-cargo-cost-summary-label">
                Full cost (without shipping)
            </div>
            <div class="fi-cargo-cost-summary-value" x-text="format(withoutShipping)"></div>
        </div>

        <div class="fi-cargo-cost-summary-item">
            <div class="fi-cargo-cost-summary-label">
                Full cost (inc. shipping)
            </div>
            <div class="fi-cargo-cost-summary-value" x-text="format(withShipping)"></div>
        </div>

        <div class="fi-cargo-cost-summary-item">
            {{ $getChildSchema() }}
        </div>

        <div class="fi-cargo-cost-summary-item">
            <div class="fi-cargo-cost-summary-label">
                Import taxes
            </div>
            <div class="fi-cargo-cost-summary-value" x-text="format(importTaxes)"></div>
        </div>
    </div>
</div>
