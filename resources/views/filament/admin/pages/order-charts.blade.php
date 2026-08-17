<x-filament-panels::page>
    <div x-data="orderChartPage()" x-init="init()" class="space-y-4">
        <script type="application/json" x-ref="chartItemsJson">@json($this->getOrderChartItems())</script>
        <script>
            function orderChartPage() {
                return {
                    chart: null,
                    items: [],
                    filters: {
                        from: "",
                        to: "",
                        customerIds: [],
                        collections: [],
                        countries: [],
                    },
                    get customerOptions() {
                        const map = new Map();
                        this.items.forEach((item) => {
                            if (item.customer_id) {
                                map.set(String(item.customer_id), item.customer_label);
                            }
                        });
                        return Array.from(map.entries())
                            .map(([id, label]) => ({ id, label }))
                            .sort((a, b) => String(a.label).localeCompare(String(b.label)));
                    },
                    get collectionOptions() {
                        const set = new Set();
                        this.items.forEach((item) => (item.collections ?? []).forEach((name) => set.add(String(name))));
                        return Array.from(set.values()).sort((a, b) => a.localeCompare(b));
                    },
                    get countryOptions() {
                        const set = new Set(this.items.map((item) => String(item.customer_country ?? "Unknown")));
                        return Array.from(set.values()).sort((a, b) => a.localeCompare(b));
                    },
                    isSelected(key, value) {
                        return this.filters[key].some((v) => String(v) === String(value));
                    },
                    toggleSelected(key, value) {
                        const id = String(value);
                        const idx = this.filters[key].findIndex((v) => String(v) === id);
                        if (idx === -1) {
                            this.filters[key].push(id);
                        } else {
                            this.filters[key].splice(idx, 1);
                        }
                        this.applyFilters();
                    },
                    buildChartData(filteredItems) {
                        if (filteredItems.length === 0) {
                            return { labels: [], totals: [], counts: [] };
                        }
                        const sortedDays = [...new Set(filteredItems.map((item) => item.day))].sort();
                        const byDay = Object.fromEntries(sortedDays.map((day) => [day, {
                            total: 0,
                            count: 0,
                        }]));
                        filteredItems.forEach((item) => {
                            byDay[item.day].total += Number(item.sum_without_shipping ?? 0);
                            byDay[item.day].count += 1;
                        });
                        return {
                            labels: sortedDays,
                            totals: sortedDays.map((day) => Number(byDay[day].total.toFixed(2))),
                            counts: sortedDays.map((day) => byDay[day].count),
                        };
                    },
                    applyFilters() {
                        const filtered = this.items.filter((item) => {
                            if (this.filters.from && item.day < this.filters.from) return false;
                            if (this.filters.to && item.day > this.filters.to) return false;
                            if (this.filters.customerIds.length > 0 && ! this.isSelected("customerIds", item.customer_id)) return false;
                            if (this.filters.collections.length > 0) {
                                const hasCollection = (item.collections ?? []).some((name) => this.isSelected("collections", name));
                                if (! hasCollection) return false;
                            }
                            if (this.filters.countries.length > 0 && ! this.isSelected("countries", item.customer_country ?? "Unknown")) return false;
                            return true;
                        });

                        const data = this.buildChartData(filtered);
                        this.chart.data.labels = data.labels;
                        this.chart.data.datasets[0].data = data.totals;
                        this.chart.data.datasets[1].data = data.counts;
                        this.chart.update();
                    },
                    init() {
                        if (typeof Chart === "undefined") {
                            setTimeout(() => this.init(), 120);
                            return;
                        }

                        this.items = JSON.parse(this.$refs.chartItemsJson?.textContent ?? "[]");
                        const data = this.buildChartData(this.items);

                        const ctx = this.$refs.canvas.getContext("2d");
                        this.chart = new Chart(ctx, {
                            type: "bar",
                            data: {
                                labels: data.labels,
                                datasets: [
                                    {
                                        label: "Orders sum",
                                        data: data.totals,
                                        backgroundColor: "rgba(43, 58, 103, 0.85)",
                                        borderColor: "rgba(43, 58, 103, 1)",
                                        borderWidth: 1,
                                        yAxisID: "yAmount",
                                    },
                                    {
                                        label: "Orders count",
                                        data: data.counts,
                                        backgroundColor: "rgba(16, 185, 129, 0.85)",
                                        borderColor: "rgba(5, 150, 105, 1)",
                                        borderWidth: 1,
                                        yAxisID: "yCount",
                                    },
                                ],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: {
                                        title: { display: true, text: "Days" },
                                    },
                                    yAmount: {
                                        beginAtZero: true,
                                        position: "left",
                                        title: { display: true, text: "Amount (without shipping)" },
                                    },
                                    yCount: {
                                        beginAtZero: true,
                                        position: "right",
                                        title: { display: true, text: "Orders count" },
                                        grid: {
                                            drawOnChartArea: false,
                                        },
                                        ticks: {
                                            precision: 0,
                                            stepSize: 1,
                                        },
                                    },
                                },
                            },
                        });
                    },
                };
            }
        </script>

        <div class="todo-calendar-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <label class="todo-date-filter">
                    <span class="todo-date-filter__label">From</span>
                    <input type="date" x-model="filters.from" @change="applyFilters()" class="todo-date-filter__input">
                </label>
                <label class="todo-date-filter">
                    <span class="todo-date-filter__label">To</span>
                    <input type="date" x-model="filters.to" @change="applyFilters()" class="todo-date-filter__input">
                </label>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <template x-for="option in customerOptions" :key="'c-' + option.id">
                    <label class="todo-filter-chip todo-filter-chip--income" :class="{ 'is-active': isSelected('customerIds', option.id) }" style="font-size: 12px; margin-right: 2px;">
                        <input type="checkbox" class="sr-only" :checked="isSelected('customerIds', option.id)" @change="toggleSelected('customerIds', option.id)">
                        <span x-text="option.label"></span>
                    </label>
                </template>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="mt-3 flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <template x-for="name in collectionOptions" :key="'col-' + name">
                    <label class="todo-filter-chip todo-filter-chip--payments" :class="{ 'is-active': isSelected('collections', name) }" style="font-size: 12px; margin-right: 2px;">
                        <input type="checkbox" class="sr-only" :checked="isSelected('collections', name)" @change="toggleSelected('collections', name)">
                        <span x-text="name"></span>
                    </label>
                </template>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="mt-3 flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <template x-for="country in countryOptions" :key="'country-' + country">
                    <label class="todo-filter-chip todo-filter-chip--new" :class="{ 'is-active': isSelected('countries', country) }" style="font-size: 12px; margin-right: 2px;">
                        <input type="checkbox" class="sr-only" :checked="isSelected('countries', country)" @change="toggleSelected('countries', country)">
                        <span x-text="country"></span>
                    </label>
                </template>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="h-[420px]">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>
    </div>
</x-filament-panels::page>
