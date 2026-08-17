<x-filament-panels::page>
    <div
        x-data='timelinePage()'
        x-init="init()"
        class="space-y-4"
    >
        <script type="application/json" x-ref="itemsJson">@json($this->getTimelineItems())</script>
        <script type="application/json" x-ref="timelineMetaJson">@json($this->getTimelineMeta())</script>

        <script>
            function timelinePage() {
                return {
                    tooltipEl: null,
                    allItems: [],
                    visibleItems: [],
                    commentsModalOpen: false,
                    selectedItem: null,
                    newCommentText: "",
                    addingComment: false,
                    savingComments: false,
                    quickViewModalOpen: false,
                    quickViewItem: null,
                    quickViewForm: {
                        title: "",
                        project_id: "",
                        status: "",
                        priority: "regular",
                        start_date: "",
                        deadline: "",
                        description: "",
                        archived: false,
                    },
                    savingQuickView: false,
                    filters: {
                        from: "",
                        to: "",
                        userIds: [],
                        financialsOnly: false,
                        income: false,
                        payments: false,
                        statusNew: false,
                        statusInprogress: false,
                        statusConfirm: false,
                        statusReturned: false,
                        statusDone: false,
                        priorityHigh: false,
                        priorityRegular: false,
                        priorityLow: false,
                    },
                    dayWidth: 28,
                    maxDays: 62,
                    days: [],
                    rangeStart: null,
                    projects: [],
                    init() {
                        const rawItems = this.$refs.itemsJson?.textContent ?? "[]";
                        this.allItems = JSON.parse(rawItems);
                        try {
                            const meta = JSON.parse(this.$refs.timelineMetaJson?.textContent ?? "{}");
                            this.projects = Array.isArray(meta.projects) ? meta.projects : [];
                        } catch (error) {
                            this.projects = [];
                        }
                        this.visibleItems = this.allItems;
                        this.buildDateRange();
                        this.applyFilters();
                    },
                    parseDate(dateText) {
                        if (! dateText) {
                            return null;
                        }
                        const date = new Date(dateText);
                        return Number.isNaN(date.getTime()) ? null : date;
                    },
                    dateToIsoStart(dateText) {
                        if (! dateText) {
                            return null;
                        }
                        return this.parseDate(`${dateText}T00:00:00`);
                    },
                    dateToIsoEnd(dateText) {
                        if (! dateText) {
                            return null;
                        }
                        return this.parseDate(`${dateText}T23:59:59`);
                    },
                    userSelected(userId) {
                        return this.filters.userIds.some((value) => String(value) === String(userId));
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
                    get userOptions() {
                        const map = new Map();
                        this.allItems.forEach((item) => {
                            if (item.user_id !== null && item.user_id !== undefined && item.user_id !== "") {
                                map.set(String(item.user_id), item.user_label ?? `User #${item.user_id}`);
                            }
                        });

                        return Array.from(map.entries())
                            .map(([id, label]) => ({ id, label }))
                            .sort((a, b) => String(a.label).localeCompare(String(b.label)));
                    },
                    startOfDay(date) {
                        const d = new Date(date);
                        d.setHours(0, 0, 0, 0);
                        return d;
                    },
                    addDays(date, days) {
                        const d = new Date(date);
                        d.setDate(d.getDate() + days);
                        return d;
                    },
                    diffInDays(start, end) {
                        return Math.floor((this.startOfDay(end) - this.startOfDay(start)) / (1000 * 60 * 60 * 24));
                    },
                    buildDateRange() {
                        const starts = this.allItems
                            .map((item) => this.parseDate(item.start))
                            .filter((d) => d !== null);
                        const ends = this.allItems
                            .map((item) => this.parseDate(item.end))
                            .filter((d) => d !== null);

                        const fallback = this.startOfDay(new Date());
                        const minDate = starts.length > 0 ? new Date(Math.min(...starts.map((d) => d.getTime()))) : fallback;
                        const maxDate = ends.length > 0 ? new Date(Math.max(...ends.map((d) => d.getTime()))) : this.addDays(fallback, 14);

                        this.rangeStart = this.startOfDay(minDate);
                        const totalDays = Math.min(this.maxDays, Math.max(7, this.diffInDays(this.rangeStart, maxDate) + 1));
                        this.days = Array.from({ length: totalDays }, (_, index) => {
                            const date = this.addDays(this.rangeStart, index);
                            return {
                                key: date.toISOString().slice(0, 10),
                                day: date.getDate(),
                                month: date.getMonth() + 1,
                            };
                        });
                    },
                    barStart(item) {
                        const start = this.parseDate(item.start);
                        if (! start || ! this.rangeStart) {
                            return 0;
                        }
                        return Math.max(0, this.diffInDays(this.rangeStart, start));
                    },
                    barSpan(item) {
                        const start = this.parseDate(item.start);
                        const end = this.parseDate(item.end);
                        if (! start || ! end) {
                            return 1;
                        }
                        return Math.max(1, this.diffInDays(start, end) + 1);
                    },
                    barStyle(item) {
                        const left = this.barStart(item) * this.dayWidth;
                        const width = this.barSpan(item) * this.dayWidth;
                        const financeStroke = item.has_financials ? "border:3px solid #dc2626;" : "";
                        return `left:${left}px;width:${width}px;background:${item.color};${financeStroke}`;
                    },
                    escapeHtml(value) {
                        return (value ?? "")
                            .toString()
                            .replace(/&/g, "&amp;")
                            .replace(/</g, "&lt;")
                            .replace(/>/g, "&gt;")
                            .replace(/"/g, "&quot;")
                            .replace(/'/g, "&#039;");
                    },
                    formatFinanceValue(value) {
                        if (value === null || value === undefined || value === "") {
                            return "0";
                        }
                        const number = Number(value);
                        if (! Number.isFinite(number)) {
                            return String(value);
                        }
                        return number.toFixed(2).replace(/\.00$/, "");
                    },
                    tooltipText(item) {
                        const hasFinance = [item.total_income, item.income_left, item.total_payment, item.payment_left]
                            .some((value) => value !== null && value !== undefined && value !== "");
                        const financeText = hasFinance
                            ? "Income: Total: "
                                + this.formatFinanceValue(item.total_income)
                                + " Left: "
                                + this.formatFinanceValue(item.income_left)
                                + "\nExpences: Total: "
                                + this.formatFinanceValue(item.total_payment)
                                + " Left: "
                                + this.formatFinanceValue(item.payment_left)
                            : "";

                        return [item.description ?? "", financeText].filter((part) => part !== "").join("\n");
                    },
                    ensureTooltip() {
                        if (this.tooltipEl) {
                            return this.tooltipEl;
                        }

                        const el = document.createElement("div");
                        el.className = "todo-calendar-tooltip";
                        el.style.position = "fixed";
                        el.style.zIndex = "9999";
                        el.style.display = "none";
                        document.body.appendChild(el);
                        this.tooltipEl = el;

                        return el;
                    },
                    showTooltip(event, title, description) {
                        const el = this.ensureTooltip();
                        const safeTitle = this.escapeHtml(title);
                        const safeDescription = this.escapeHtml(description);
                        const descriptionHtml = safeDescription !== ""
                            ? "<div class=\"todo-calendar-tooltip__description\">" + safeDescription + "</div>"
                            : "";

                        el.innerHTML = "<div class=\"todo-calendar-tooltip__title\">" + safeTitle + "</div>" + descriptionHtml;
                        el.style.display = "block";
                        this.moveTooltip(event);
                    },
                    moveTooltip(event) {
                        if (! this.tooltipEl || this.tooltipEl.style.display === "none") {
                            return;
                        }
                        const offset = 14;
                        const maxLeft = window.innerWidth - this.tooltipEl.offsetWidth - 8;
                        const maxTop = window.innerHeight - this.tooltipEl.offsetHeight - 8;
                        const left = Math.min(event.clientX + offset, Math.max(8, maxLeft));
                        const top = Math.min(event.clientY + offset, Math.max(8, maxTop));
                        this.tooltipEl.style.left = left + "px";
                        this.tooltipEl.style.top = top + "px";
                    },
                    hideTooltip() {
                        if (this.tooltipEl) {
                            this.tooltipEl.style.display = "none";
                        }
                    },
                    applyFilters() {
                        this.visibleItems = this.allItems.filter((item) => {
                            if (this.filters.userIds.length > 0 && ! this.userSelected(item.user_id)) {
                                return false;
                            }

                            const fromDate = this.dateToIsoStart(this.filters.from);
                            const toDate = this.dateToIsoEnd(this.filters.to);
                            if (fromDate || toDate) {
                                const start = this.parseDate(item.start);
                                const end = this.parseDate(item.end);
                                if (! start || ! end) {
                                    return false;
                                }
                                if (fromDate && end < fromDate) {
                                    return false;
                                }
                                if (toDate && start > toDate) {
                                    return false;
                                }
                            }

                            if (this.filters.financialsOnly && ! item.has_financials) {
                                return false;
                            }
                            if (this.filters.income && ! item.has_income) {
                                return false;
                            }
                            if (this.filters.payments && ! item.has_payments) {
                                return false;
                            }

                            const statusFiltersActive = this.filters.statusNew
                                || this.filters.statusInprogress
                                || this.filters.statusConfirm
                                || this.filters.statusReturned
                                || this.filters.statusDone;
                            if (statusFiltersActive) {
                                const allowed = (
                                    (this.filters.statusNew && item.status === "new")
                                    || (this.filters.statusInprogress && item.status === "inprogress")
                                    || (this.filters.statusConfirm && item.status === "confirm")
                                    || (this.filters.statusReturned && item.status === "returned")
                                    || (this.filters.statusDone && item.status === "done")
                                );
                                if (! allowed) {
                                    return false;
                                }
                            }

                            const priorityFiltersActive = this.filters.priorityHigh
                                || this.filters.priorityRegular
                                || this.filters.priorityLow;
                            if (priorityFiltersActive) {
                                const priority = String(item.priority ?? "regular").toLowerCase();
                                const allowedPriority = (
                                    (this.filters.priorityHigh && priority === "high")
                                    || (this.filters.priorityRegular && priority === "regular")
                                    || (this.filters.priorityLow && priority === "low")
                                );
                                if (! allowedPriority) {
                                    return false;
                                }
                            }
                            return true;
                        });
                    },
                    openCommentsModal(item) {
                        this.selectedItem = JSON.parse(JSON.stringify(item));
                        this.newCommentText = "";
                        this.commentsModalOpen = true;
                    },
                    closeCommentsModal() {
                        this.commentsModalOpen = false;
                        this.selectedItem = null;
                        this.newCommentText = "";
                    },
                    async addCommentFromModal() {
                        if (! this.selectedItem || this.addingComment) {
                            return;
                        }

                        const content = this.newCommentText?.trim() ?? "";
                        if (content === "") {
                            return;
                        }

                        this.addingComment = true;
                        try {
                            const ok = await this.$wire.addComment(this.selectedItem.id, content);
                            if (! ok) {
                                return;
                            }

                            const refreshed = await this.$wire.getTimelineItems();
                            this.allItems = Array.isArray(refreshed) ? refreshed : [];
                            this.applyFilters();
                            this.buildDateRange();
                            this.selectedItem = this.allItems.find((entry) => entry.id === this.selectedItem.id) ?? null;
                            this.newCommentText = "";
                        } finally {
                            this.addingComment = false;
                        }
                    },
                    openQuickView(item) {
                        this.quickViewItem = JSON.parse(JSON.stringify(item));
                        this.quickViewForm = {
                            title: item.title ?? "",
                            project_id: item.project_id ? String(item.project_id) : "",
                            status: item.status ?? "new",
                            priority: item.priority ?? "regular",
                            start_date: item.start ? item.start.slice(0, 16) : "",
                            deadline: item.end ? item.end.slice(0, 16) : "",
                            description: item.full_description ?? item.description ?? "",
                            archived: Boolean(item.archived),
                        };
                        this.quickViewModalOpen = true;
                    },
                    async handleItemClick(event, item, mode = "none") {
                        if (event.ctrlKey || event.metaKey) {
                            event.preventDefault();
                            event.stopPropagation();
                            await this.archiveTodo(item.id);
                            return;
                        }

                        if (mode === "quickView") {
                            this.openQuickView(item);
                        }
                    },
                    async archiveTodo(todoId) {
                        if (! todoId) {
                            return;
                        }

                        this.hideTooltip();

                        try {
                            const result = await this.$wire.archiveTodo(todoId);
                            if (! result?.ok) {
                                return;
                            }

                            this.allItems = Array.isArray(result.items) ? result.items : [];
                            this.applyFilters();
                            this.buildDateRange();
                        } catch (error) {
                            console.error(error);
                            window.alert("Could not archive task. Please try again.");
                        }
                    },
                    closeQuickView() {
                        this.quickViewModalOpen = false;
                        this.quickViewItem = null;
                        this.savingQuickView = false;
                    },
                    async saveQuickView() {
                        if (! this.quickViewItem || this.savingQuickView) {
                            return;
                        }
                        if (! this.quickViewItem.can_edit) {
                            return;
                        }

                        this.savingQuickView = true;
                        try {
                            const payload = {
                                title: this.quickViewForm.title,
                                project_id: this.quickViewForm.project_id ? Number(this.quickViewForm.project_id) : null,
                                status: this.quickViewForm.status,
                                priority: this.quickViewForm.priority || "regular",
                                start_date: this.quickViewForm.start_date ? `${this.quickViewForm.start_date}:00` : null,
                                deadline: this.quickViewForm.deadline ? `${this.quickViewForm.deadline}:00` : null,
                                description: this.quickViewForm.description ?? "",
                                archived: Boolean(this.quickViewForm.archived),
                            };
                            const ok = await this.$wire.updateTodoQuickView(this.quickViewItem.id, payload);
                            if (! ok) {
                                return;
                            }

                            const refreshed = await this.$wire.getTimelineItems();
                            this.allItems = Array.isArray(refreshed) ? refreshed : [];
                            this.applyFilters();
                            this.buildDateRange();
                            this.closeQuickView();
                        } finally {
                            this.savingQuickView = false;
                        }
                    },
                    async saveCommentsFromModal() {
                        if (! this.selectedItem || this.savingComments) {
                            return;
                        }

                        this.savingComments = true;
                        try {
                            const ok = await this.$wire.saveComments(
                                this.selectedItem.id,
                                this.selectedItem.comments ?? [],
                                this.newCommentText ?? "",
                            );
                            if (! ok) {
                                return;
                            }

                            const refreshed = await this.$wire.getTimelineItems();
                            this.allItems = Array.isArray(refreshed) ? refreshed : [];
                            this.applyFilters();
                            this.buildDateRange();
                            this.selectedItem = this.allItems.find((entry) => entry.id === this.selectedItem.id) ?? null;
                            this.newCommentText = "";
                        } finally {
                            this.savingComments = false;
                        }
                    },
                    toggleDeleteComment(comment) {
                        comment.delete = ! comment.delete;
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
                <label class="todo-filter-chip todo-filter-chip--finance" :class="{ 'is-active': filters.financialsOnly }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.financialsOnly" @change="applyFilters()">
                    <span>Financials only</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--income" :class="{ 'is-active': filters.income }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.income" @change="applyFilters()">
                    <span>Income</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--payments" :class="{ 'is-active': filters.payments }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.payments" @change="applyFilters()">
                    <span>Expences</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--new" :class="{ 'is-active': filters.statusNew }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.statusNew" @change="applyFilters()">
                    <span>New</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--inprogress" :class="{ 'is-active': filters.statusInprogress }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.statusInprogress" @change="applyFilters()">
                    <span>In progress</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--confirm" :class="{ 'is-active': filters.statusConfirm }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.statusConfirm" @change="applyFilters()">
                    <span>Confirm</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--returned" :class="{ 'is-active': filters.statusReturned }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.statusReturned" @change="applyFilters()">
                    <span>Returned</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--done" :class="{ 'is-active': filters.statusDone }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.statusDone" @change="applyFilters()">
                    <span>Done</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--priority-high" :class="{ 'is-active': filters.priorityHigh }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.priorityHigh" @change="applyFilters()">
                    <span>High</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--priority-regular" :class="{ 'is-active': filters.priorityRegular }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.priorityRegular" @change="applyFilters()">
                    <span>Regular</span>
                </label>
                <label class="todo-filter-chip todo-filter-chip--priority-low" :class="{ 'is-active': filters.priorityLow }" style="font-size: 12px; margin-right: 2px;">
                    <input type="checkbox" class="sr-only" x-model="filters.priorityLow" @change="applyFilters()">
                    <span>Low</span>
                </label>
            </div>
            <div class="chart-filter-separator"></div>
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <template x-for="option in userOptions" :key="'timeline-user-' + option.id">
                    <label class="todo-filter-chip todo-filter-chip--income" :class="{ 'is-active': userSelected(option.id) }" style="font-size: 12px; margin-right: 2px;">
                        <input type="checkbox" class="sr-only" :checked="userSelected(option.id)" @change="toggleUserFilter(option.id)">
                        <span x-text="option.label"></span>
                    </label>
                </template>
            </div>
        </div>

        <div class="timeline-gantt rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="overflow-x-auto" x-show="visibleItems.length > 0">
                <div class="min-w-max">
                    <div class="timeline-gantt-header">
                        <div>Task</div>
                        <div>Comments</div>
                        <div>Start</div>
                        <div>End</div>
                        <div>Duration</div>
                        <div class="timeline-gantt-days" :style="'grid-template-columns: repeat(' + days.length + ', ' + dayWidth + 'px);'">
                            <template x-for="day in days" :key="day.key">
                                <div class="timeline-gantt-day" x-text="day.day"></div>
                            </template>
                        </div>
                    </div>

                    <template x-for="item in visibleItems" :key="item.id">
                        <div class="timeline-gantt-row">
                            <div class="flex items-center gap-2">
                                <button type="button" class="inline-flex items-center text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" @click="handleItemClick($event, item, 'quickView')" title="Quick view (Ctrl+click to archive)">
                                    <x-heroicon-o-eye class="h-4 w-4" />
                                </button>
                                <a :href="item.edit_url" class="timeline-gantt-task" x-text="item.display_title ?? item.title" @click="handleItemClick($event, item, 'navigate')"></a>
                            </div>
                            <div>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-md border border-gray-200 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
                                    @click="openCommentsModal(item)"
                                >
                                    <x-heroicon-o-chat-bubble-left-right class="h-3.5 w-3.5" />
                                    <span x-text="item.comments_count ?? 0"></span>
                                </button>
                            </div>
                            <div class="timeline-gantt-meta" x-text="item.start_label"></div>
                            <div class="timeline-gantt-meta" x-text="item.end_label"></div>
                            <div class="timeline-gantt-meta" x-text="item.duration_label"></div>
                            <div class="timeline-gantt-track" :style="'width:' + (days.length * dayWidth) + 'px'">
                                <div class="timeline-gantt-grid" :style="'grid-template-columns: repeat(' + days.length + ', ' + dayWidth + 'px);'">
                                    <template x-for="day in days" :key="day.key + '-' + item.id">
                                        <div class="timeline-gantt-cell"></div>
                                    </template>
                                </div>
                                <div
                                    class="timeline-gantt-bar"
                                    :style="barStyle(item)"
                                    @click="handleItemClick($event, item)"
                                    @mouseenter="showTooltip($event, item.display_title ?? item.title, tooltipText(item))"
                                    @mousemove="moveTooltip($event)"
                                    @mouseleave="hideTooltip()"
                                >
                                    <span x-text="item.display_title ?? item.title"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div x-show="visibleItems.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                No timeline items match current filters.
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="chart-legend">
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #ffffff; border: 3px solid #dc2626;"></span>
                    <span>Finances (red stroke)</span>
                </span>
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #6b7280;"></span>
                    <span>New</span>
                </span>
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #f59e0b;"></span>
                    <span>In progress</span>
                </span>
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #2563eb;"></span>
                    <span>Confirm</span>
                </span>
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #9333ea;"></span>
                    <span>Returned</span>
                </span>
                <span class="chart-legend__item">
                    <span class="chart-legend__dot" style="background-color: #16a34a;"></span>
                    <span>Done</span>
                </span>
            </div>
        </div>

        <div
            x-show="commentsModalOpen"
            x-cloak
            class="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4"
            @keydown.escape.window="closeCommentsModal()"
        >
            <div class="w-full max-w-3xl rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                        Comments: <span x-text="selectedItem?.title ?? ''"></span>
                    </h3>
                    <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" @click="closeCommentsModal()">✕</button>
                </div>

                <div class="max-h-[55vh] space-y-3 overflow-y-auto p-4">
                    <template x-if="!selectedItem || !selectedItem.comments || selectedItem.comments.length === 0">
                        <div class="rounded-lg border border-dashed border-gray-300 px-3 py-4 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            No comments yet.
                        </div>
                    </template>

                    <template x-for="comment in (selectedItem?.comments ?? [])" :key="comment.id">
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="mb-1 text-xs text-gray-500 dark:text-gray-400">
                                <span x-text="comment.user"></span>
                                <span> | </span>
                                <span x-text="comment.date"></span>
                            </div>
                            <template x-if="comment.is_owner">
                                <div class="space-y-2">
                                    <textarea
                                        x-model="comment.content"
                                        rows="3"
                                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    ></textarea>
                                    <button
                                        type="button"
                                        class="text-xs text-red-600 underline hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                                        @click="toggleDeleteComment(comment)"
                                        x-text="comment.delete ? 'Undo delete' : 'Delete comment'"
                                    ></button>
                                </div>
                            </template>
                            <template x-if="!comment.is_owner">
                                <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200" x-text="comment.content"></div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="border-t border-gray-200 p-4 dark:border-gray-700">
                    <label class="mb-2 block text-xs font-medium text-gray-600 dark:text-gray-300">Add comment</label>
                    <textarea
                        x-model="newCommentText"
                        rows="4"
                        class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        placeholder="Write comment..."
                    ></textarea>
                    <div class="mt-3 flex justify-end gap-2">
                        <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" @click="closeCommentsModal()">Close</button>
                        <button type="button" class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60" :disabled="savingComments" @click="saveCommentsFromModal()">
                            <span x-show="!savingComments">Save comments</span>
                            <span x-show="savingComments">Saving...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div
            x-show="quickViewModalOpen"
            x-cloak
            class="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4"
            @keydown.escape.window="closeQuickView()"
        >
            <div class="w-full max-w-3xl rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                        Task: <span x-text="quickViewItem?.display_title ?? quickViewItem?.title ?? ''"></span>
                    </h3>
                    <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" @click="closeQuickView()">✕</button>
                </div>

                <div class="space-y-3 p-4">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Title</label>
                            <input type="text" x-model="quickViewForm.title" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Project <span class="text-red-600">*</span></label>
                            <select x-model="quickViewForm.project_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)" required>
                                <option value="">Select project</option>
                                <template x-for="project in projects.filter((entry) => !entry.archived || String(entry.id) === String(quickViewForm.project_id))" :key="project.id">
                                    <option :value="String(project.id)" x-text="project.label"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Status</label>
                            <select x-model="quickViewForm.status" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                <option value="new">New</option>
                                <option value="inprogress">In progress</option>
                                <option value="confirm">Confirm</option>
                                <option value="returned">Returned</option>
                                <option value="done">Done</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Priority</label>
                            <select x-model="quickViewForm.priority" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                <option value="high">High</option>
                                <option value="regular">Regular</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="timeline-quick-view-archived" x-model="quickViewForm.archived" class="rounded border-gray-300 text-primary-600" :disabled="!(quickViewItem?.can_edit)">
                            <label for="timeline-quick-view-archived" class="text-xs font-medium text-gray-600 dark:text-gray-300">Archived</label>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <label class="mb-1 block font-medium text-gray-600 dark:text-gray-300">Author</label>
                            <div x-text="quickViewItem?.user_label ?? '—'"></div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Start date</label>
                            <input type="datetime-local" x-model="quickViewForm.start_date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Deadline</label>
                            <input type="datetime-local" x-model="quickViewForm.deadline" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Description</label>
                            <textarea x-model="quickViewForm.description" rows="5" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-200 p-4 dark:border-gray-700">
                    <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" @click="closeQuickView()">Close</button>
                    <button type="button" class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60" :disabled="savingQuickView || !(quickViewItem?.can_edit)" @click="saveQuickView()">
                        <span x-show="!savingQuickView">Save changes</span>
                        <span x-show="savingQuickView">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
