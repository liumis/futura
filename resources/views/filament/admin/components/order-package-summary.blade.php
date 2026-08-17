<div
    wire:ignore
    x-data="{
        packageProfiles: @js($packageProfiles),
        packageCount: 0,
        paletteCount: 0,
        totals: {
            totalWeight: 0,
            plastic: 0,
            cardboardI: 0,
            cardboardII: 0,
            wood: 0,
        },
        formatKg(value) {
            return Number(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 3,
                maximumFractionDigits: 3,
            }) + ' kg';
        },
        totalItems() {
            const amounts = $wire.get('data.order_amounts') ?? $wire.data?.order_amounts ?? {};

            return Object.values(amounts).reduce((sum, amount) => {
                const qty = parseInt(amount || 0, 10);

                if (! Number.isFinite(qty) || qty <= 0) {
                    return sum;
                }

                return sum + qty;
            }, 0);
        },
        selectedPackage() {
            const id = $wire.get('data.package_id') ?? $wire.data?.package_id ?? @js($initialPackageId);

            return this.packageProfiles[id] ?? this.packageProfiles[String(id)] ?? null;
        },
        update() {
            const selected = this.selectedPackage();
            const items = this.totalItems();

            if (! selected) {
                this.packageCount = 0;
                this.paletteCount = 0;
                this.totals = {
                    totalWeight: 0,
                    plastic: 0,
                    cardboardI: 0,
                    cardboardII: 0,
                    wood: 0,
                };

                return;
            }

            const itemsOnPalette = Math.max(1, parseInt(selected.items_on_palette || 0, 10));
            this.packageCount = items;
            this.paletteCount = items > 0 ? Math.max(1, Math.ceil(items / itemsOnPalette)) : 0;

            this.totals.plastic = this.packageCount * (parseFloat(selected.plastic_weight || 0) || 0);
            this.totals.cardboardI = this.packageCount * (parseFloat(selected.cardboard_i_weight || 0) || 0);
            this.totals.cardboardII = this.packageCount * (parseFloat(selected.cardboard_ii_weight || 0) || 0);
            this.totals.wood = this.paletteCount * (parseFloat(selected.palette_weight || 0) || 0);
            this.totals.totalWeight = this.packageCount * (parseFloat(selected.total_weight || 0) || 0) + this.totals.wood;
        },
    }"
    x-init="
        update();
        $wire.$watch('data.order_amounts', () => update(), { deep: true });
        $wire.$watch('data.package_id', () => update());
    "
    class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900"
>
    <div class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">Package</div>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Number of packages</div>
            <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="packageCount"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Palletes</div>
            <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="paletteCount"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Total package weight</div>
            <div class="mt-1 text-lg font-semibold text-gray-900 dark:text-white" x-text="formatKg(totals.totalWeight)"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Plastic</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-white" x-text="formatKg(totals.plastic)"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Cardboard I</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-white" x-text="formatKg(totals.cardboardI)"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500 dark:text-gray-400">Cardboard II</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-white" x-text="formatKg(totals.cardboardII)"></div>
        </div>

        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700 md:col-span-2 xl:col-span-3">
            <div class="text-xs text-gray-500 dark:text-gray-400">Wood (palletes)</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-white" x-text="formatKg(totals.wood)"></div>
        </div>
    </div>
</div>
