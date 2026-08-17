<x-filament-panels::page>
    <script>
        function paymentsChartPage(initialItems = []) {
            return {
                chart: null,
                activeChartMode: null,
                items: Array.isArray(initialItems) ? initialItems : [],
                filters: {
                    from: "",
                    to: "",
                    preset: "current_q",
                    chartMode: "finances",
                    userIds: [],
                    income: false,
                    payments: false,
                    incomeLeftOnly: false,
                    paymentLeftOnly: false,
                    statusNew: false,
                    statusInprogress: false,
                    statusConfirm: false,
                    statusReturned: false,
                    statusDone: false,
                    priorityHigh: false,
                    priorityRegular: false,
                    priorityLow: false,
                },
                financeDatasets: [
                    {
                        label: "Expences total",
                        backgroundColor: "rgba(220, 38, 38, 0.85)",
                        borderColor: "rgba(220, 38, 38, 1)",
                    },
                    {
                        label: "Expences left",
                        backgroundColor: "rgba(248, 113, 113, 0.95)",
                        borderColor: "rgba(239, 68, 68, 1)",
                    },
                    {
                        label: "Expences paid",
                        backgroundColor: "rgba(185, 28, 28, 0.65)",
                        borderColor: "rgba(153, 27, 27, 1)",
                    },
                    {
                        label: "Income total",
                        backgroundColor: "rgba(43, 58, 103, 0.85)",
                        borderColor: "rgba(43, 58, 103, 1)",
                    },
                    {
                        label: "Income left",
                        backgroundColor: "rgba(96, 165, 250, 0.95)",
                        borderColor: "rgba(59, 130, 246, 1)",
                    },
                    {
                        label: "Income paid",
                        backgroundColor: "rgba(30, 64, 175, 0.65)",
                        borderColor: "rgba(29, 78, 216, 1)",
                    },
                ],
                datePresets: [
                    { key: "previous_q", label: "Previous Q" },
                    { key: "current_q", label: "Current Q" },
                    { key: "next_q", label: "Next Q" },
                    { key: "previous_h", label: "Previous H" },
                    { key: "current_h", label: "Current H" },
                    { key: "next_h", label: "Next H" },
                    { key: "last_y", label: "Last Y" },
                    { key: "current_y", label: "Current Y" },
                    { key: "next_y", label: "Next Y" },
                ],
                get userOptions() {
                    const map = new Map();
                    this.items.forEach((item) => {
                        if (item.user_id !== null && item.user_id !== undefined && item.user_id !== "") {
                            map.set(String(item.user_id), item.user_label ?? `User #${item.user_id}`);
                        }
                    });

                    return Array.from(map.entries())
                        .map(([id, label]) => ({ id, label }))
                        .sort((a, b) => String(a.label).localeCompare(String(b.label)));
                },
                toggleUserFilter(userId) {
                    const id = String(userId);
                    const idx = this.filters.userIds.findIndex((value) => String(value) === id);
                    if (idx === -1) {
                        this.filters.userIds.push(id);
                    } else {
                        this.filters.userIds.splice(idx, 1);
                    }
                    this.applyFilters();
                },
                userSelected(userId) {
                    return this.filters.userIds.some((value) => String(value) === String(userId));
                },
                toggleFilter(key) {
                    this.filters[key] = ! this.filters[key];
                    this.applyFilters();
                },
                itemMatchesStatus(item) {
                    const status = String(item.status ?? "").toLowerCase();
                    const selected = [];

                    if (this.filters.statusNew) selected.push("new");
                    if (this.filters.statusInprogress) selected.push("inprogress");
                    if (this.filters.statusConfirm) selected.push("confirm");
                    if (this.filters.statusReturned) selected.push("returned");
                    if (this.filters.statusDone) selected.push("done");

                    if (selected.length === 0) {
                        return true;
                    }

                    return selected.includes(status);
                },
                itemMatchesPriority(item) {
                    const priority = String(item.priority ?? "regular").toLowerCase();
                    const selected = [];

                    if (this.filters.priorityHigh) selected.push("high");
                    if (this.filters.priorityRegular) selected.push("regular");
                    if (this.filters.priorityLow) selected.push("low");

                    if (selected.length === 0) {
                        return true;
                    }

                    return selected.includes(priority);
                },
                pad2(n) {
                    return String(n).padStart(2, "0");
                },
                formatDateParts(year, monthIndex, day) {
                    return `${year}-${this.pad2(monthIndex + 1)}-${this.pad2(day)}`;
                },
                rangeFromMonths(year, startMonth, monthCount) {
                    const from = new Date(Date.UTC(year, startMonth, 1));
                    const to = new Date(Date.UTC(year, startMonth + monthCount, 0));

                    return {
                        from: this.formatDateParts(from.getUTCFullYear(), from.getUTCMonth(), from.getUTCDate()),
                        to: this.formatDateParts(to.getUTCFullYear(), to.getUTCMonth(), to.getUTCDate()),
                    };
                },
                presetRange(key) {
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = now.getMonth();
                    const quarter = Math.floor(month / 3);
                    const half = Math.floor(month / 6);

                    switch (key) {
                        case "previous_q": {
                            const start = new Date(Date.UTC(year, (quarter - 1) * 3, 1));
                            return this.rangeFromMonths(start.getUTCFullYear(), start.getUTCMonth(), 3);
                        }
                        case "current_q":
                            return this.rangeFromMonths(year, quarter * 3, 3);
                        case "next_q": {
                            const start = new Date(Date.UTC(year, (quarter + 1) * 3, 1));
                            return this.rangeFromMonths(start.getUTCFullYear(), start.getUTCMonth(), 3);
                        }
                        case "previous_h": {
                            const start = new Date(Date.UTC(year, (half - 1) * 6, 1));
                            return this.rangeFromMonths(start.getUTCFullYear(), start.getUTCMonth(), 6);
                        }
                        case "current_h":
                            return this.rangeFromMonths(year, half * 6, 6);
                        case "next_h": {
                            const start = new Date(Date.UTC(year, (half + 1) * 6, 1));
                            return this.rangeFromMonths(start.getUTCFullYear(), start.getUTCMonth(), 6);
                        }
                        case "last_y":
                            return this.rangeFromMonths(year - 1, 0, 12);
                        case "current_y":
                            return this.rangeFromMonths(year, 0, 12);
                        case "next_y":
                            return this.rangeFromMonths(year + 1, 0, 12);
                        default:
                            return this.rangeFromMonths(year, quarter * 3, 3);
                    }
                },
                applyPreset(key) {
                    const range = this.presetRange(key);
                    this.filters.preset = key;
                    this.filters.from = range.from;
                    this.filters.to = range.to;
                    this.applyFilters();
                },
                onManualDateChange() {
                    this.filters.preset = "";
                    this.applyFilters();
                },
                setChartMode(mode) {
                    this.filters.chartMode = mode;
                    this.activeChartMode = null;
                    this.$nextTick(() => this.applyFilters());
                },
                parseIsoDay(value) {
                    const match = String(value ?? "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (! match) {
                        return null;
                    }

                    return {
                        year: Number(match[1]),
                        monthIndex: Number(match[2]) - 1,
                        day: Number(match[3]),
                    };
                },
                daysInRange(from, to) {
                    const start = this.parseIsoDay(from);
                    const end = this.parseIsoDay(to);
                    if (! start || ! end) {
                        return [];
                    }

                    const days = [];
                    let cursor = Date.UTC(start.year, start.monthIndex, start.day);
                    const endUtc = Date.UTC(end.year, end.monthIndex, end.day);

                    if (cursor > endUtc) {
                        return [];
                    }

                    while (cursor <= endUtc) {
                        const date = new Date(cursor);
                        days.push(this.formatDateParts(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));
                        cursor += 24 * 60 * 60 * 1000;
                    }

                    return days;
                },
                displayLabel(day) {
                    const parts = this.parseIsoDay(day);
                    if (! parts) {
                        return day;
                    }

                    return `${this.pad2(parts.monthIndex + 1)}-${this.pad2(parts.day)}`;
                },
                buildChartData(filteredItems) {
                    let sortedDays = this.daysInRange(this.filters.from, this.filters.to);

                    if (sortedDays.length === 0) {
                        sortedDays = [...new Set(filteredItems.map((item) => item.day))].sort();
                    }

                    const byDay = {};
                    sortedDays.forEach((day) => {
                        byDay[day] = {
                            incomeTotal: 0,
                            incomeLeft: 0,
                            paymentTotal: 0,
                            paymentLeft: 0,
                            taskCount: 0,
                            hasValues: false,
                        };
                    });

                    filteredItems.forEach((item) => {
                        const day = String(item.day ?? "");
                        if (! byDay[day]) {
                            return;
                        }

                        byDay[day].taskCount += 1;

                        const incomeTotal = Number(item.total_income ?? 0);
                        const incomeLeft = Number(item.income_left ?? 0);
                        const paymentTotal = Number(item.total_payment ?? 0);
                        const paymentLeft = Number(item.payment_left ?? 0);

                        byDay[day].incomeTotal += incomeTotal;
                        byDay[day].incomeLeft += incomeLeft;
                        byDay[day].paymentTotal += paymentTotal;
                        byDay[day].paymentLeft += paymentLeft;

                        if (
                            item.has_financials
                            || incomeTotal
                            || incomeLeft
                            || paymentTotal
                            || paymentLeft
                        ) {
                            byDay[day].hasValues = true;
                        }
                    });

                    const valueOrNull = (day, value) => {
                        if (! byDay[day].hasValues) {
                            return null;
                        }

                        return Number(Number(value).toFixed(2));
                    };

                    const countOrNull = (day) => {
                        const count = byDay[day].taskCount;
                        return count > 0 ? count : null;
                    };

                    return {
                        labels: sortedDays.map((day) => this.displayLabel(day)),
                        taskCount: sortedDays.map((day) => countOrNull(day)),
                        incomeTotal: sortedDays.map((day) => valueOrNull(day, byDay[day].incomeTotal)),
                        incomeLeft: sortedDays.map((day) => valueOrNull(day, byDay[day].incomeLeft)),
                        incomePaid: sortedDays.map((day) => valueOrNull(day, Math.max(0, byDay[day].incomeTotal - byDay[day].incomeLeft))),
                        paymentTotal: sortedDays.map((day) => valueOrNull(day, byDay[day].paymentTotal)),
                        paymentLeft: sortedDays.map((day) => valueOrNull(day, byDay[day].paymentLeft)),
                        paymentPaid: sortedDays.map((day) => valueOrNull(day, Math.max(0, byDay[day].paymentTotal - byDay[day].paymentLeft))),
                    };
                },
                filteredItems() {
                    const isFinances = this.filters.chartMode === "finances";

                    return this.items.filter((item) => {
                        const day = String(item.day ?? "");
                        if (this.filters.from && day < this.filters.from) return false;
                        if (this.filters.to && day > this.filters.to) return false;
                        if (isFinances && ! item.has_financials) return false;
                        if (this.filters.userIds.length > 0 && ! this.userSelected(item.user_id)) return false;

                        if (isFinances) {
                            if (this.filters.income && ! item.has_income) return false;
                            if (this.filters.payments && ! item.has_payments) return false;
                            if (this.filters.incomeLeftOnly && ! item.has_income_left) return false;
                            if (this.filters.paymentLeftOnly && ! item.has_payment_left) return false;
                        }

                        if (! this.itemMatchesStatus(item)) {
                            return false;
                        }

                        if (! this.itemMatchesPriority(item)) {
                            return false;
                        }

                        return true;
                    });
                },
                buildChartConfig(mode, data) {
                    const isTasks = mode === "tasks";
                    const taskValues = data.taskCount.map((value) => (value == null ? null : Number(value)));
                    const maxTaskCount = taskValues.reduce((max, value) => {
                        if (value == null) {
                            return max;
                        }

                        return Math.max(max, value);
                    }, 0);

                    const datasets = isTasks
                        ? [
                            {
                                label: "Tasks count",
                                data: taskValues,
                                backgroundColor: "rgba(43, 58, 103, 0.85)",
                                borderColor: "rgba(43, 58, 103, 1)",
                                borderWidth: 1,
                            },
                        ]
                        : [
                            {
                                label: this.financeDatasets[0].label,
                                data: data.paymentTotal,
                                backgroundColor: this.financeDatasets[0].backgroundColor,
                                borderColor: this.financeDatasets[0].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                            {
                                label: this.financeDatasets[1].label,
                                data: data.paymentLeft,
                                backgroundColor: this.financeDatasets[1].backgroundColor,
                                borderColor: this.financeDatasets[1].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                            {
                                label: this.financeDatasets[2].label,
                                data: data.paymentPaid,
                                backgroundColor: this.financeDatasets[2].backgroundColor,
                                borderColor: this.financeDatasets[2].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                            {
                                label: this.financeDatasets[3].label,
                                data: data.incomeTotal,
                                backgroundColor: this.financeDatasets[3].backgroundColor,
                                borderColor: this.financeDatasets[3].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                            {
                                label: this.financeDatasets[4].label,
                                data: data.incomeLeft,
                                backgroundColor: this.financeDatasets[4].backgroundColor,
                                borderColor: this.financeDatasets[4].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                            {
                                label: this.financeDatasets[5].label,
                                data: data.incomePaid,
                                backgroundColor: this.financeDatasets[5].backgroundColor,
                                borderColor: this.financeDatasets[5].borderColor,
                                borderWidth: 1,
                                minBarLength: 3,
                            },
                        ];

                    return {
                        type: "bar",
                        data: {
                            labels: data.labels,
                            datasets,
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            spanGaps: false,
                            animation: false,
                            plugins: {
                                legend: {
                                    display: false,
                                },
                            },
                            datasets: {
                                bar: {
                                    categoryPercentage: 0.9,
                                    barPercentage: 0.9,
                                },
                            },
                            scales: {
                                x: {
                                    type: "category",
                                    title: {
                                        display: true,
                                        text: "Days",
                                    },
                                    ticks: {
                                        autoSkip: true,
                                        maxRotation: 90,
                                        minRotation: 45,
                                        font: {
                                            size: 10,
                                        },
                                    },
                                },
                                y: {
                                    beginAtZero: true,
                                    suggestedMax: isTasks ? Math.max(maxTaskCount, 1) : undefined,
                                    ticks: {
                                        precision: 0,
                                        stepSize: isTasks ? 1 : undefined,
                                    },
                                    title: {
                                        display: true,
                                        text: isTasks ? "Count" : "Amount",
                                    },
                                },
                            },
                        },
                    };
                },
                renderChart() {
                    if (typeof Chart === "undefined" || ! this.$refs.canvas) {
                        return;
                    }

                    const mode = this.filters.chartMode === "tasks" ? "tasks" : "finances";
                    const data = this.buildChartData(this.filteredItems());
                    const dayCount = data.labels.length;
                    const canvasWrap = this.$refs.canvasWrap;
                    if (canvasWrap) {
                        canvasWrap.style.minWidth = `${Math.max(640, dayCount * 14)}px`;
                    }

                    if (this.chart) {
                        this.chart.destroy();
                        this.chart = null;
                    }

                    const canvas = this.$refs.canvas;
                    const ctx = canvas.getContext("2d");
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    this.chart = new Chart(ctx, this.buildChartConfig(mode, data));
                    this.activeChartMode = mode;
                },
                applyFilters() {
                    try {
                        this.renderChart();
                    } catch (error) {
                        console.error("Chart update failed", error);
                    }
                },
                init() {
                    if (typeof Chart === "undefined") {
                        setTimeout(() => this.init(), 120);
                        return;
                    }

                    const range = this.presetRange("current_q");
                    this.filters.preset = "current_q";
                    this.filters.from = range.from;
                    this.filters.to = range.to;

                    this.$nextTick(() => this.renderChart());
                },
            };
        }
    </script>

    <div wire:ignore>
    <div
        x-data="paymentsChartPage(@js($this->getChartItems()))"
        x-init="init()"
        class="space-y-4"
    >

        <div class="todo-calendar-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <label class="todo-date-filter">
                    <span class="todo-date-filter__label">From</span>
                    <input type="date" x-model="filters.from" @change="onManualDateChange()" class="todo-date-filter__input">
                </label>
                <label class="todo-date-filter">
                    <span class="todo-date-filter__label">To</span>
                    <input type="date" x-model="filters.to" @change="onManualDateChange()" class="todo-date-filter__input">
                </label>
                <template x-for="preset in datePresets" :key="preset.key">
                    <button
                        type="button"
                        class="todo-filter-chip todo-filter-chip--confirm"
                        :class="{ 'is-active': filters.preset === preset.key }"
                        style="font-size: 12px; margin-right: 2px;"
                        @click="applyPreset(preset.key)"
                        x-text="preset.label"
                    ></button>
                </template>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <button
                    type="button"
                    class="todo-filter-chip todo-filter-chip--finance"
                    :class="{ 'is-active': filters.chartMode === 'finances' }"
                    style="font-size: 12px; margin-right: 2px;"
                    @click="setChartMode('finances')"
                >Finances</button>
                <button
                    type="button"
                    class="todo-filter-chip todo-filter-chip--confirm"
                    :class="{ 'is-active': filters.chartMode === 'tasks' }"
                    style="font-size: 12px; margin-right: 2px;"
                    @click="setChartMode('tasks')"
                >Tasks</button>
            </div>
            <div class="chart-filter-separator" x-show="filters.chartMode === 'finances'"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;" x-show="filters.chartMode === 'finances'">
                <button type="button" class="todo-filter-chip todo-filter-chip--income" :class="{ 'is-active': filters.income }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('income')">Income</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--payments" :class="{ 'is-active': filters.payments }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('payments')">Expences</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--income" :class="{ 'is-active': filters.incomeLeftOnly }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('incomeLeftOnly')">Income left only</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--payments" :class="{ 'is-active': filters.paymentLeftOnly }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('paymentLeftOnly')">Expences left only</button>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <button type="button" class="todo-filter-chip todo-filter-chip--new" :class="{ 'is-active': filters.statusNew }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('statusNew')">New</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--inprogress" :class="{ 'is-active': filters.statusInprogress }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('statusInprogress')">In progress</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--confirm" :class="{ 'is-active': filters.statusConfirm }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('statusConfirm')">Confirm</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--returned" :class="{ 'is-active': filters.statusReturned }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('statusReturned')">Returned</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--done" :class="{ 'is-active': filters.statusDone }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('statusDone')">Done</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--priority-high" :class="{ 'is-active': filters.priorityHigh }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityHigh')">High</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--priority-regular" :class="{ 'is-active': filters.priorityRegular }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityRegular')">Regular</button>
                <button type="button" class="todo-filter-chip todo-filter-chip--priority-low" :class="{ 'is-active': filters.priorityLow }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityLow')">Low</button>
            </div>

            <div class="chart-filter-separator"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <template x-for="option in userOptions" :key="'user-' + option.id">
                    <button
                        type="button"
                        class="todo-filter-chip todo-filter-chip--income"
                        :class="{ 'is-active': userSelected(option.id) }"
                        style="font-size: 12px; margin-right: 2px;"
                        @click="toggleUserFilter(option.id)"
                        x-text="option.label"
                    ></button>
                </template>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 overflow-x-auto">
            <div class="h-[420px]" x-ref="canvasWrap">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <div class="chart-legend" x-show="filters.chartMode === 'finances'">
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(220, 38, 38, 0.85);"></span>
                <span>Expences total</span>
            </span>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(248, 113, 113, 0.95);"></span>
                <span>Expences left (unpaid)</span>
            </span>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(185, 28, 28, 0.65);"></span>
                <span>Expences paid</span>
            </span>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(43, 58, 103, 0.85);"></span>
                <span>Income total</span>
            </span>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(96, 165, 250, 0.95);"></span>
                <span>Income left</span>
            </span>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(30, 64, 175, 0.65);"></span>
                <span>Income paid</span>
            </span>
        </div>
        <div class="chart-legend" x-show="filters.chartMode === 'tasks'" x-cloak>
            <span class="chart-legend__item">
                <span class="chart-legend__dot" style="background-color: rgba(43, 58, 103, 0.85);"></span>
                <span>Tasks count</span>
            </span>
        </div>
    </div>
    </div>
</x-filament-panels::page>
