@php
    $initialAdditionalCost = (float) ($initialAdditionalCost ?? 0);
    $initialWithShipping = (float) ($initialWithoutShipping ?? 0) + (float) ($initialShippingCost ?? 0) + $initialAdditionalCost;
    $initialRate = $initialImportTaxId
        ? (float) ($importTaxRates[$initialImportTaxId] ?? $importTaxRates[(string) $initialImportTaxId] ?? 0)
        : 0.0;
    $initialImportTaxes = round($initialWithShipping * $initialRate / 100, 2);
@endphp

<div
    wire:ignore
    x-data="{
        productCosts: @js($productCosts),
        productSizes: @js($productSizes ?? []),
        importTaxRates: @js($importTaxRates),
        withoutShipping: @js($initialWithoutShipping),
        withShipping: @js($initialWithShipping),
        importTaxes: @js($initialImportTaxes),
        format(amount) {
            return '€' + Number(amount || 0).toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        },
        wireValue(path) {
            try {
                if (typeof $wire?.get === 'function') {
                    return $wire.get(path);
                }
            } catch (error) {
                // Livewire state may not be ready yet.
            }

            try {
                return path.split('.').reduce((value, key) => value?.[key], $wire);
            } catch (error) {
                return undefined;
            }
        },
        collectAmounts() {
            const amounts = {};

            document.querySelectorAll('[data-cargo-product-amount]').forEach((input) => {
                const productId = input.dataset.cargoProductAmount;

                if (productId) {
                    amounts[productId] = input.value;
                }
            });

            if (Object.keys(amounts).length > 0) {
                return amounts;
            }

            return { ...(this.wireValue('data.cargo_amounts') ?? {}) };
        },
        collectCosts() {
            const costs = { ...this.productCosts };

            document.querySelectorAll('[data-cargo-product-cost]').forEach((input) => {
                const productId = input.dataset.cargoProductCost;

                if (productId && input.value !== '') {
                    costs[productId] = parseFloat(input.value) || 0;
                }
            });

            const formCosts = this.wireValue('data.cargo_costs') ?? {};

            for (const [id, cost] of Object.entries(formCosts)) {
                if (cost !== null && cost !== '' && (costs[id] === undefined || costs[id] === null)) {
                    costs[id] = parseFloat(cost) || 0;
                }
            }

            return costs;
        },
        getShippingCost() {
            const input = document.querySelector('[data-cargo-shipping-cost]');

            if (input && input.value !== '') {
                return parseFloat(input.value) || 0;
            }

            return parseFloat(this.wireValue('data.shipping_cost') ?? 0) || 0;
        },
        getAdditionalCost() {
            const input = document.querySelector('[data-cargo-additional-cost]');

            if (input && input.value !== '') {
                return parseFloat(input.value) || 0;
            }

            return parseFloat(this.wireValue('data.additional_cost') ?? 0) || 0;
        },
        getImportTaxId() {
            return this.wireValue('data.import_tax_id') ?? null;
        },
        hasAmountSources() {
            if (document.querySelector('[data-cargo-product-amount]')) {
                return true;
            }

            const wireAmounts = this.wireValue('data.cargo_amounts');

            return wireAmounts && typeof wireAmounts === 'object' && Object.keys(wireAmounts).length > 0;
        },
        update() {
            // Avoid wiping the server-rendered total before Livewire/DOM amounts exist.
            if (! this.hasAmountSources()) {
                return;
            }

            const amounts = this.collectAmounts();
            const costs = this.collectCosts();
            let total = 0;

            for (const [id, amount] of Object.entries(amounts)) {
                const qty = parseInt(amount || 0, 10);

                if (! Number.isFinite(qty) || qty <= 0) {
                    continue;
                }

                const cost = parseFloat(costs[id] ?? costs[parseInt(id, 10)] ?? 0) || 0;
                const meters = this.productSizes[id] ?? this.productSizes[parseInt(id, 10)] ?? null;
                total += meters !== null && meters > 0
                    ? qty * meters * cost
                    : qty * cost;
            }

            const shipping = this.getShippingCost();
            const additional = this.getAdditionalCost();
            const taxId = this.getImportTaxId();
            const rate = taxId
                ? (this.importTaxRates[taxId] ?? this.importTaxRates[String(taxId)] ?? 0)
                : 0;

            this.withoutShipping = Math.round(total * 100) / 100;
            this.withShipping = Math.round((total + shipping + additional) * 100) / 100;
            this.importTaxes = Math.round((this.withShipping * rate / 100) * 100) / 100;
        },
        init() {
            const run = () => this.update();

            run();
            this.$nextTick(run);
            setTimeout(run, 50);
            setTimeout(run, 250);
            setTimeout(run, 1000);

            document.addEventListener('input', (event) => {
                if (event.target?.matches?.('[data-cargo-product-amount], [data-cargo-product-cost], [data-cargo-shipping-cost], [data-cargo-additional-cost]')) {
                    run();
                }
            }, true);

            document.addEventListener('change', (event) => {
                if (event.target?.matches?.('[data-cargo-product-amount], [data-cargo-product-cost], [data-cargo-shipping-cost], [data-cargo-additional-cost], select')) {
                    run();
                }
            }, true);

            document.addEventListener('livewire:navigated', run);

            if (typeof Livewire !== 'undefined') {
                Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => queueMicrotask(run));
                });
            }

            try {
                $wire.$watch('data.cargo_amounts', run, { deep: true });
                $wire.$watch('data.cargo_costs', run, { deep: true });
                $wire.$watch('data.shipping_cost', run);
                $wire.$watch('data.additional_cost', run);
                $wire.$watch('data.import_tax_id', run);
            } catch (error) {
                // Watchers are optional; DOM events still recalculate.
            }
        },
    }"
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
