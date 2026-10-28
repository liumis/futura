<x-filament-panels::page>
    <style>
        .contacts-dashboard {
            color: #222222;
            font-family: 'Jost', sans-serif;
            font-weight: 400;
        }
        .contacts-dashboard .mono,
        .contact-kanban-column__title,
        .contact-kanban-column__count,
        .contact-date-list__title,
        .contact-date-list__count,
        .contact-date-list__meta,
        .contact-date-list__late,
        .contact-date-list__empty,
        .contact-date-list__footer a,
        .contact-kanban-card__note,
        .contact-kanban-card__due,
        .contact-qv__label,
        .contact-qv__loading,
        .contact-qv__link,
        .contact-qv__comment-date {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .contact-kanban-board {
            display: flex;
            gap: 28px;
            align-items: flex-start;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        .contact-kanban-column {
            display: flex;
            min-width: 280px;
            min-height: 420px;
            flex: 1 0 280px;
            flex-direction: column;
            border: 1px solid #C9C9C9;
            background-color: #FFFFFF;
        }
        .contact-kanban-column__header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 1px solid #222222;
            margin: 12px 16px 4px;
            padding: 0 0 6px;
            background-color: transparent;
        }
        .contact-kanban-column__title {
            font-size: 10px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .contact-kanban-column__count {
            font-size: 9px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .contact-kanban-column__body {
            display: flex;
            flex: 1;
            flex-direction: column;
            padding: 0 16px 14px;
            min-height: 360px;
        }
        .contact-kanban-card {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid #F2F2F2;
            background-color: #FFFFFF;
            cursor: grab;
        }
        .contact-kanban-card:active { cursor: grabbing; }
        .contact-kanban-card--ghost { opacity: 0.45; }
        .contact-kanban-card--chosen { background-color: #FFFFFF; }
        .contact-kanban-card__handle {
            display: flex;
            align-items: flex-start;
            padding: 11px 0 0;
            font-size: 11px;
            line-height: 1;
            color: #C9C9C9;
            cursor: grab;
            user-select: none;
            visibility: hidden;
        }
        .contact-kanban-card:hover .contact-kanban-card__handle {
            visibility: visible;
        }
        .contact-kanban-card__body {
            flex: 1;
            min-width: 0;
            padding: 9px 0;
            cursor: pointer;
        }
        .contact-kanban-card__title {
            font-size: 14px;
            font-weight: 400;
            color: #222222;
            line-height: 1.45;
            text-transform: uppercase;
        }
        .contact-kanban-card__note {
            margin-top: 2px;
            font-size: 11px;
            letter-spacing: 0.04em;
            color: #6B6B6B;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .contact-kanban-card__dues {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            margin-top: 4px;
        }
        .contact-kanban-card__due {
            font-size: 8px;
            letter-spacing: 0.12em;
            color: #6B6B6B;
            white-space: nowrap;
        }
        .contact-kanban-card__due.is-late {
            color: #222222;
            font-weight: 500;
        }
        .contact-kanban-card__due.is-late::before {
            content: '';
            display: inline-block;
            width: 5px;
            height: 5px;
            margin-right: 6px;
            border-radius: 999px;
            background: #EB8027;
            vertical-align: middle;
        }
        .contact-kanban-column__footer {
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid #F2F2F2;
        }
        .contact-kanban-column__footer a {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-size: 9px;
            color: #6B6B6B;
            cursor: pointer;
            text-decoration: none;
        }
        .contact-kanban-column__footer a:hover {
            color: #222222;
        }
        .contact-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        @media (min-width: 768px) {
            .contact-summary {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (min-width: 1100px) {
            .contact-summary {
                grid-template-columns: repeat(6, minmax(0, 1fr));
            }
        }
        .contact-summary__item {
            border: 1px solid #C9C9C9;
            background: #FFFFFF;
            padding: 14px 16px 12px;
            min-height: 78px;
        }
        .contact-summary__item--alert {
            border-color: #E8C4C0;
            background: #FDF8F7;
        }
        .contact-summary__label {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-size: 9px;
            color: #6B6B6B;
        }
        .contact-summary__value {
            margin-top: 8px;
            font-family: 'Jost', sans-serif;
            font-size: 28px;
            line-height: 1;
            color: #222222;
            font-weight: 400;
        }
        .contact-summary__item--alert .contact-summary__value {
            color: #222222;
            font-weight: 500;
        }
        .contact-summary__item--alert .contact-summary__label::before {
            content: '';
            display: inline-block;
            width: 5px;
            height: 5px;
            margin-right: 6px;
            border-radius: 999px;
            background: #EB8027;
            vertical-align: middle;
        }
        .contact-date-lists {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 768px) {
            .contact-date-lists {
                grid-template-columns: 1fr 1fr;
            }
        }
        .contact-date-list {
            display: flex;
            flex-direction: column;
            border: 1px solid #C9C9C9;
            background-color: #FFFFFF;
            padding: 12px 16px 14px;
        }
        .contact-date-list__header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 1px solid #222222;
            padding: 0 0 6px;
            margin-bottom: 4px;
        }
        .contact-date-list__title {
            font-size: 10px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .contact-date-list__count {
            font-size: 9px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .contact-date-list__body {
            display: flex;
            flex-direction: column;
        }
        .contact-date-list__row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #F2F2F2;
            padding: 9px 0;
            cursor: pointer;
        }
        .contact-date-list__row:hover {
            background-color: #FFFFFF;
        }
        .contact-date-list__name {
            font-size: 14px;
            font-weight: 400;
            color: #222222;
            line-height: 1.45;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .contact-date-list__main {
            display: flex;
            align-items: baseline;
            gap: 10px;
            min-width: 0;
            flex: 1;
        }
        .contact-date-list__comment {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 12px;
            font-weight: 400;
            line-height: 1.45;
            color: #6B6B6B;
            text-transform: none;
            letter-spacing: 0;
        }
        .contact-date-list__meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 8px;
            letter-spacing: 0.12em;
            color: #6B6B6B;
            white-space: nowrap;
            padding-top: 4px;
            flex-shrink: 0;
        }
        .contact-date-list__row--late .contact-date-list__name,
        .contact-date-list__row--late .contact-date-list__meta,
        .contact-date-list__row--late .contact-date-list__comment {
            color: #222222;
            font-weight: 500;
        }
        .contact-date-list__late {
            font-size: 8px;
            letter-spacing: 0.12em;
            color: #222222;
            font-weight: 500;
        }
        .contact-date-list__late::before {
            content: '';
            display: inline-block;
            width: 5px;
            height: 5px;
            margin-right: 6px;
            border-radius: 999px;
            background: #EB8027;
            vertical-align: middle;
        }
        .contact-date-list__empty {
            padding: 10px 0;
            font-size: 9px;
            color: #6B6B6B;
        }
        .contact-date-list__footer {
            padding-top: 4px;
        }
        .contact-date-list__footer a {
            border: none;
            background: none;
            font-size: 9px;
            color: #6B6B6B;
            text-decoration: none;
            cursor: pointer;
            padding: 10px 0;
            display: inline-block;
        }
        .contact-date-list__footer a:hover {
            color: #222222;
        }
        .contact-qv {
            font-family: 'Jost', sans-serif;
            color: #222222;
        }
        .contact-qv__box {
            display: flex;
            max-height: 90vh;
            width: 100%;
            max-width: 32rem;
            flex-direction: column;
            border: 1px solid #222222;
            background: #FFFFFF;
        }
        .contact-qv__header,
        .contact-qv__footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #222222;
            padding: 14px 20px;
        }
        .contact-qv__tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #F2F2F2;
            padding: 0 12px;
        }
        .contact-qv__tab {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            background: none;
            padding: 12px 12px 10px;
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 9px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B6B6B;
            cursor: pointer;
        }
        .contact-qv__tab.is-active {
            color: #222222;
        }
        .contact-qv__tab.is-active::after {
            content: "";
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: -1px;
            height: 2px;
            background: #222222;
        }
        .contact-qv__tab-count {
            display: inline-flex;
            min-width: 1.25rem;
            align-items: center;
            justify-content: center;
            border: 1px solid #C9C9C9;
            padding: 1px 5px;
            font-size: 9px;
            color: #6B6B6B;
        }
        .contact-qv__footer {
            border-bottom: none;
            border-top: 1px solid #F2F2F2;
            justify-content: flex-end;
        }
        .contact-qv__title {
            font-size: 16px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .contact-qv__close {
            border: none;
            background: none;
            color: #6B6B6B;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .contact-qv__close:hover { color: #222222; }
        .contact-qv__body {
            overflow-y: auto;
            padding: 20px;
        }
        .contact-qv__label {
            display: block;
            margin-bottom: 6px;
            font-size: 9px;
            color: #6B6B6B;
        }
        .contact-qv__input,
        .contact-qv__box textarea {
            width: 100%;
            border: 1px solid #C9C9C9;
            background: #FFFFFF;
            padding: 8px 10px;
            font-family: 'Jost', sans-serif;
            font-size: 14px;
            color: #222222;
            outline: none;
            border-radius: 0;
        }
        .contact-qv__input:focus,
        .contact-qv__box textarea:focus {
            border-color: #222222;
            background: #FFFFFF;
        }
        .contact-qv__comments {
            max-height: 10rem;
            overflow-y: auto;
            border-top: 1px solid #F2F2F2;
        }
        .contact-qv__comment {
            border-bottom: 1px solid #F2F2F2;
            padding: 8px 0;
        }
        .contact-qv__comment-date {
            font-size: 8px;
            color: #6B6B6B;
        }
        .contact-qv__comment-text {
            margin-top: 4px;
            font-size: 13px;
            white-space: pre-wrap;
        }
        .contact-qv__link {
            font-size: 9px;
            color: #6B6B6B;
            text-decoration: none;
        }
        .contact-qv__link:hover { color: #222222; }
        .contact-qv__person {
            border: 1px solid #F2F2F2;
            padding: 12px;
        }
        .contact-qv__person-name {
            font-size: 14px;
            font-weight: 500;
            color: #222222;
        }
        .contact-qv__person-meta {
            margin-top: 4px;
            font-size: 12px;
            color: #444444;
        }
        .contact-qv__person-note {
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.45;
            color: #6B6B6B;
        }
        .contact-qv__empty {
            padding: 18px 0;
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 9px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #6B6B6B;
        }
        .contact-qv__btn {
            border: none;
            background: #222222;
            color: #FFFFFF;
            padding: 10px 20px;
            font-family: 'Jost', sans-serif;
            font-size: 11px;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            cursor: pointer;
        }
        .contact-qv__btn:hover { background: #444444; }
        .contact-qv__btn:disabled { opacity: 0.5; cursor: default; }
        .contact-qv__btn--ghost {
            background: #FFFFFF;
            color: #222222;
            border: 1px solid #222222;
        }
        .contact-qv__btn--ghost:hover { background: #FFFFFF; }
        .contact-qv__loading {
            padding: 32px 0;
            text-align: center;
            font-size: 9px;
            color: #6B6B6B;
        }
    </style>

    <div class="contacts-dashboard space-y-8">
        @php($summary = $this->getSummaryStats())
        <div class="contact-summary" wire:key="contacts-board-summary">
            <div class="contact-summary__item">
                <div class="contact-summary__label">Total contacts</div>
                <div class="contact-summary__value">{{ number_format($summary['total']) }}</div>
            </div>
            <div class="contact-summary__item{{ $summary['needs_contact'] > 0 ? ' contact-summary__item--alert' : '' }}">
                <div class="contact-summary__label">Needs contact</div>
                <div class="contact-summary__value">{{ number_format($summary['needs_contact']) }}</div>
            </div>
            <div class="contact-summary__item{{ $summary['overdue'] > 0 ? ' contact-summary__item--alert' : '' }}">
                <div class="contact-summary__label">Overdue</div>
                <div class="contact-summary__value">{{ number_format($summary['overdue']) }}</div>
            </div>
            <div class="contact-summary__item">
                <div class="contact-summary__label">Due today</div>
                <div class="contact-summary__value">{{ number_format($summary['due_today']) }}</div>
            </div>
            <div class="contact-summary__item">
                <div class="contact-summary__label">Upcoming</div>
                <div class="contact-summary__value">{{ number_format($summary['upcoming']) }}</div>
            </div>
            <div class="contact-summary__item">
                <div class="contact-summary__label">Never contacted</div>
                <div class="contact-summary__value">{{ number_format($summary['never_contacted']) }}</div>
            </div>
        </div>

        <div class="fi-contacts-board-filters rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {{ $this->filtersForm }}
        </div>

        <div
            wire:ignore
            x-data="contactsBoardComponent()"
            x-init="init()"
            class="space-y-8"
        >
            <script type="application/json" x-ref="kanbanItemsJson">@json($this->getKanbanItems())</script>
            <script type="application/json" x-ref="kanbanColumnsJson">@json($this->getKanbanColumns())</script>

            <script>
                function contactsBoardComponent() {
                    return {
                        initialized: false,
                        sortables: [],
                        items: [],
                        columns: [],
                        movingCard: false,
                        suppressCardClick: false,
                        dateListLimit: 20,
                        columnCardLimit: 20,
                        expandedStages: {},
                        showAllLastContacted: false,
                        showAllUpcoming: false,
                        quickViewModalOpen: false,
                        quickViewLoading: false,
                        savingQuickView: false,
                        quickViewTab: "summary",
                        quickViewItem: null,
                        quickViewForm: {
                            type: "",
                            phone: "",
                            email: "",
                            last_contact: "",
                            next_contact: "",
                            comment: "",
                            comments: [],
                            persons: [],
                        },
                        todayDate() {
                            const now = new Date();
                            const month = String(now.getMonth() + 1).padStart(2, "0");
                            const day = String(now.getDate()).padStart(2, "0");

                            return `${now.getFullYear()}-${month}-${day}`;
                        },
                        emptyQuickViewForm() {
                            return {
                                type: "",
                                phone: "",
                                email: "",
                                last_contact: "",
                                next_contact: "",
                                comment: "",
                                comments: [],
                                persons: [],
                            };
                        },
                        init() {
                            if (this.initialized) {
                                return;
                            }

                            try {
                                this.items = JSON.parse(this.$refs.kanbanItemsJson?.textContent ?? "[]");
                                this.columns = JSON.parse(this.$refs.kanbanColumnsJson?.textContent ?? "[]");
                            } catch (error) {
                                console.error(error);
                                this.items = [];
                                this.columns = [];
                            }

                            this.initialized = true;
                            this.$nextTick(() => {
                                this.renderBoard();
                                this.initSortable();
                            });

                            const applyFilteredItems = (nextItems) => {
                                this.items = Array.isArray(nextItems) ? nextItems : [];
                                this.$nextTick(() => {
                                    this.renderBoard();
                                    this.initSortable();
                                });
                            };

                            // Keep Alpine board in sync when Livewire filters change (filters live outside wire:ignore).
                            Livewire.on("contacts-board-filtered", (payload) => {
                                const detail = Array.isArray(payload) ? payload[0] : payload;
                                applyFilteredItems(detail?.items ?? detail ?? []);
                            });

                            const wire = this.getLivewire?.() ?? this.$wire;
                            if (wire?.$watch) {
                                wire.$watch("filters", async () => {
                                    try {
                                        applyFilteredItems(await wire.getKanbanItems());
                                    } catch (error) {
                                        console.error(error);
                                    }
                                });
                            }
                        },
                        itemsForStage(stage) {
                            return this.items.filter((item) => String(item.stage) === String(stage));
                        },
                        visibleItemsForStage(stage) {
                            const cards = this.itemsForStage(stage);
                            if (this.expandedStages[String(stage)]) {
                                return cards;
                            }

                            return cards.slice(0, this.columnCardLimit);
                        },
                        isStageExpanded(stage) {
                            return Boolean(this.expandedStages[String(stage)]);
                        },
                        toggleStageExpanded(stage) {
                            const key = String(stage);
                            this.expandedStages = {
                                ...this.expandedStages,
                                [key]: ! this.expandedStages[key],
                            };
                            this.renderBoard();
                            this.$nextTick(() => this.initSortable());
                        },
                        countForStage(stage) {
                            return this.itemsForStage(stage).length;
                        },
                        rankedByDate(key, direction) {
                            const rows = this.items.filter((item) => String(item[key] ?? "").trim() !== "");
                            rows.sort((a, b) => {
                                const left = String(a[key] ?? "");
                                const right = String(b[key] ?? "");

                                return direction === "desc" ? right.localeCompare(left) : left.localeCompare(right);
                            });

                            return rows;
                        },
                        lastContactedAll() {
                            return this.rankedByDate("last_contact", "desc");
                        },
                        lastContactedItems() {
                            const rows = this.lastContactedAll();

                            return this.showAllLastContacted ? rows : rows.slice(0, this.dateListLimit);
                        },
                        upcomingContactAll() {
                            return this.rankedByDate("next_contact", "asc");
                        },
                        upcomingContactItems() {
                            const rows = this.upcomingContactAll();

                            return this.showAllUpcoming ? rows : rows.slice(0, this.dateListLimit);
                        },
                        isUpcomingLate(item) {
                            const date = String(item?.next_contact ?? "").trim();

                            return date !== "" && date < this.todayDate();
                        },
                        dateListName(item) {
                            const name = String(item?.title ?? "").trim();
                            const country = String(item?.country ?? "").trim();

                            if (name === "") {
                                return country !== "" && country !== "—" ? country : "";
                            }

                            if (country === "" || country === "—") {
                                return name;
                            }

                            return name + " | " + country;
                        },
                        destroySortable() {
                            (this.sortables ?? []).forEach((sortable) => sortable.destroy());
                            this.sortables = [];
                        },
                        columnBodies() {
                            return Array.from(this.$el.querySelectorAll(".contact-kanban-column__body"));
                        },
                        initSortable() {
                            if (typeof Sortable === "undefined") {
                                setTimeout(() => this.initSortable(), 100);
                                return;
                            }

                            this.destroySortable();

                            this.columnBodies().forEach((body) => {
                                const sortable = Sortable.create(body, {
                                    group: {
                                        name: "contact-kanban",
                                        pull: true,
                                        put: true,
                                    },
                                    animation: 150,
                                    draggable: ".contact-kanban-card",
                                    ghostClass: "contact-kanban-card--ghost",
                                    chosenClass: "contact-kanban-card--chosen",
                                    emptyInsertThreshold: 40,
                                    onStart: () => {
                                        this.suppressCardClick = true;
                                    },
                                    onAdd: (evt) => this.onCardAdded(evt),
                                });
                                this.sortables.push(sortable);
                            });
                        },
                        async onCardAdded(evt) {
                            if (this.movingCard) {
                                return;
                            }

                            const contactId = Number(evt.item?.dataset?.contactId ?? 0);
                            const toEl = evt.to?.closest?.(".contact-kanban-column__body") ?? evt.to;
                            const fromEl = evt.from?.closest?.(".contact-kanban-column__body") ?? evt.from;
                            const newStage = toEl?.dataset?.stage ?? "";
                            const oldStage = fromEl?.dataset?.stage ?? "";

                            if (! contactId || ! newStage || newStage === oldStage) {
                                return;
                            }

                            const item = this.items.find((entry) => Number(entry.id) === contactId);
                            if (item) {
                                item.stage = newStage;
                            }

                            this.movingCard = true;
                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const result = await wire.call("moveContactStage", contactId, newStage);
                                if (result?.ok && Array.isArray(result.items)) {
                                    this.items = result.items;
                                } else {
                                    if (item) {
                                        item.stage = oldStage;
                                    }
                                    if (result?.message) {
                                        window.alert(result.message);
                                    }
                                }
                            } catch (error) {
                                console.error(error);
                                if (item) {
                                    item.stage = oldStage;
                                }
                                window.alert("Could not move contact. Please try again.");
                            } finally {
                                this.movingCard = false;
                                this.renderBoard();
                                this.$nextTick(() => {
                                    this.initSortable();
                                    setTimeout(() => {
                                        this.suppressCardClick = false;
                                    }, 50);
                                });
                            }
                        },
                        renderBoard() {
                            this.columnBodies().forEach((body) => {
                                const stage = body.dataset.stage ?? "";
                                const allCards = this.itemsForStage(stage);
                                const cards = this.visibleItemsForStage(stage);
                                body.innerHTML = "";

                                cards.forEach((item) => {
                                    body.appendChild(this.createCardElement(item));
                                });

                                if (allCards.length > this.columnCardLimit) {
                                    const footer = document.createElement("div");
                                    footer.className = "contact-kanban-column__footer";
                                    const link = document.createElement("a");
                                    link.href = "#";
                                    link.textContent = this.isStageExpanded(stage)
                                        ? "Show top 20"
                                        : `Show all (${allCards.length})`;
                                    link.addEventListener("click", (event) => {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        this.toggleStageExpanded(stage);
                                    });
                                    footer.appendChild(link);
                                    body.appendChild(footer);
                                }
                            });
                        },
                        createCardElement(item) {
                            const card = document.createElement("div");
                            card.className = "contact-kanban-card";
                            card.dataset.contactId = String(item.id);

                            const comments = Number(item.comments_count ?? 0);
                            const nextLate = this.isUpcomingLate(item);
                            const details = [item.type, item.segment, item.priority, item.country, item.phone]
                                .map((value) => String(value ?? "").trim())
                                .filter((value) => value !== "" && value !== "—")
                                .join(" · ");

                            card.innerHTML = `
                                <div class="contact-kanban-card__handle" title="Drag to move">⋮⋮</div>
                                <div class="contact-kanban-card__body">
                                    <div class="contact-kanban-card__title">${this.escapeHtml(item.title ?? "")}</div>
                                    ${details ? `<div class="contact-kanban-card__note">${this.escapeHtml(details)}</div>` : ""}
                                    <div class="contact-kanban-card__dues">
                                        <span class="contact-kanban-card__due">Last ${this.escapeHtml(item.last_contact || "—")}</span>
                                        <span class="contact-kanban-card__due${nextLate ? " is-late" : ""}">Next ${this.escapeHtml(item.next_contact || "—")}</span>
                                        ${comments > 0 ? `<span class="contact-kanban-card__due">${comments} notes</span>` : ""}
                                    </div>
                                </div>
                            `;

                            const body = card.querySelector(".contact-kanban-card__body");
                            body?.addEventListener("click", () => {
                                if (this.suppressCardClick || this.movingCard) {
                                    return;
                                }

                                this.openQuickView(item);
                            });
                            card.addEventListener("mouseenter", (event) => this.showCardTooltip(event, item));
                            card.addEventListener("mousemove", (event) => this.moveTooltip(event));
                            card.addEventListener("mouseleave", () => this.hideTooltip());

                            return card;
                        },
                        async openQuickView(item) {
                            const contactId = Number(item?.id ?? 0);
                            if (! contactId || this.movingCard) {
                                return;
                            }

                            this.hideTooltip();
                            this.quickViewLoading = true;
                            this.quickViewTab = "summary";
                            this.quickViewItem = {
                                id: contactId,
                                title: item.title ?? "",
                                edit_url: item.edit_url ?? null,
                            };
                            this.quickViewForm = this.emptyQuickViewForm();
                            await this.$nextTick();
                            this.quickViewModalOpen = true;

                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const data = await wire.call("getContactQuickView", contactId);
                                if (! data) {
                                    window.alert("Contact not found.");
                                    this.closeQuickView();
                                    return;
                                }

                                this.quickViewItem = {
                                    id: data.id,
                                    title: data.title ?? "",
                                    edit_url: data.edit_url ?? null,
                                };
                                this.quickViewForm = {
                                    type: data.type ?? "",
                                    phone: data.phone ?? "",
                                    email: data.email ?? "",
                                    last_contact: data.last_contact ?? "",
                                    next_contact: data.next_contact ?? "",
                                    comment: "",
                                    comments: Array.isArray(data.comments) ? data.comments : [],
                                    persons: Array.isArray(data.persons) ? data.persons : [],
                                };
                            } catch (error) {
                                console.error(error);
                                window.alert("Could not load contact.");
                                this.closeQuickView();
                            } finally {
                                this.quickViewLoading = false;
                            }
                        },
                        closeQuickView() {
                            this.quickViewModalOpen = false;
                            this.quickViewItem = null;
                            this.quickViewLoading = false;
                            this.savingQuickView = false;
                            this.quickViewTab = "summary";
                            this.quickViewForm = this.emptyQuickViewForm();
                        },
                        async saveQuickView() {
                            if (! this.quickViewItem || this.savingQuickView || this.quickViewLoading) {
                                return;
                            }

                            this.savingQuickView = true;
                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const result = await wire.call(
                                    "saveContactQuickView",
                                    Number(this.quickViewItem.id),
                                    {
                                        type: this.quickViewForm.type || "",
                                        phone: this.quickViewForm.phone || "",
                                        email: this.quickViewForm.email || "",
                                        last_contact: this.quickViewForm.last_contact || "",
                                        next_contact: this.quickViewForm.next_contact || "",
                                        comment: this.quickViewForm.comment ?? "",
                                    },
                                );

                                if (! result?.ok) {
                                    window.alert(result?.message || "Could not save contact.");
                                    return;
                                }

                                if (Array.isArray(result.items)) {
                                    this.items = result.items;
                                }

                                this.closeQuickView();
                                this.renderBoard();
                                this.$nextTick(() => this.initSortable());
                            } catch (error) {
                                console.error(error);
                                window.alert("Could not save contact. Please try again.");
                            } finally {
                                this.savingQuickView = false;
                            }
                        },
                        getLivewire() {
                            const root = this.$el?.closest?.('[wire\\:id]');
                            const componentId = root?.getAttribute("wire:id");

                            if (componentId && window.Livewire?.find) {
                                return window.Livewire.find(componentId);
                            }

                            return this.$wire;
                        },
                        escapeHtml(value) {
                            return (value ?? "")
                                .toString()
                                .replace(/&/g, "&amp;")
                                .replace(/</g, "&lt;")
                                .replace(/>/g, "&gt;")
                                .replace(/"/g, "&quot;")
                                .split(String.fromCharCode(39))
                                .join("&#039;");
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
                        showCardTooltip(event, item) {
                            const comment = String(item.last_comment ?? "").trim();
                            const commentedAt = String(item.last_comment_at ?? "").trim();

                            if (! comment) {
                                return;
                            }

                            const el = this.ensureTooltip();
                            const titleHtml = commentedAt
                                ? "<div class=\"todo-calendar-tooltip__title\">"
                                    + this.escapeHtml(commentedAt)
                                    + "</div>"
                                : "";

                            el.innerHTML = titleHtml
                                + "<div class=\"todo-calendar-tooltip__description\">"
                                + this.escapeHtml(comment)
                                + "</div>";
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
                            if (! this.tooltipEl) {
                                return;
                            }

                            this.tooltipEl.style.display = "none";
                        },
                    };
                }
            </script>

            <div class="contact-kanban-board">
                @foreach ($this->getKanbanColumns() as $column)
                    <div class="contact-kanban-column">
                        <div class="contact-kanban-column__header">
                            <span class="contact-kanban-column__title">{{ $column['label'] }}</span>
                            <span class="contact-kanban-column__count" x-text="countForStage('{{ $column['value'] }}')"></span>
                        </div>
                        <div
                            class="contact-kanban-column__body"
                            data-stage="{{ $column['value'] }}"
                        ></div>
                    </div>
                @endforeach
            </div>

            <div class="contact-date-lists">
                <div class="contact-date-list">
                    <div class="contact-date-list__header">
                        <span class="contact-date-list__title">Last contacted</span>
                        <span class="contact-date-list__count" x-text="lastContactedAll().length"></span>
                    </div>
                    <div class="contact-date-list__body">
                        <template x-for="item in lastContactedItems()" :key="'last-' + item.id">
                            <div class="contact-date-list__row" @click="openQuickView(item)">
                                <div class="contact-date-list__main">
                                    <span class="contact-date-list__name" x-text="dateListName(item)"></span>
                                    <span
                                        class="contact-date-list__comment"
                                        x-show="item.last_comment_preview"
                                        x-text="item.last_comment_preview"
                                        :title="item.last_comment || ''"
                                    ></span>
                                </div>
                                <span class="contact-date-list__meta" x-text="item.last_contact || '—'"></span>
                            </div>
                        </template>
                        <div class="contact-date-list__empty" x-show="lastContactedAll().length === 0">No contacts yet.</div>
                    </div>
                    <div class="contact-date-list__footer" x-show="lastContactedAll().length > dateListLimit">
                        <a x-show="!showAllLastContacted" @click.prevent="showAllLastContacted = true">Show all</a>
                        <a x-show="showAllLastContacted" @click.prevent="showAllLastContacted = false">Show top 20</a>
                    </div>
                </div>

                <div class="contact-date-list">
                    <div class="contact-date-list__header">
                        <span class="contact-date-list__title">Upcoming contact</span>
                        <span class="contact-date-list__count" x-text="upcomingContactAll().length"></span>
                    </div>
                    <div class="contact-date-list__body">
                        <template x-for="item in upcomingContactItems()" :key="'next-' + item.id">
                            <div class="contact-date-list__row" :class="{ 'contact-date-list__row--late': isUpcomingLate(item) }" @click="openQuickView(item)">
                                <div class="contact-date-list__main">
                                    <span class="contact-date-list__name" x-text="dateListName(item)"></span>
                                    <span
                                        class="contact-date-list__comment"
                                        x-show="item.last_comment_preview"
                                        x-text="item.last_comment_preview"
                                        :title="item.last_comment || ''"
                                    ></span>
                                </div>
                                <span class="contact-date-list__meta">
                                    <span class="contact-date-list__late" x-show="isUpcomingLate(item)">Late</span>
                                    <span x-text="item.next_contact || '—'"></span>
                                </span>
                            </div>
                        </template>
                        <div class="contact-date-list__empty" x-show="upcomingContactAll().length === 0">No contacts yet.</div>
                    </div>
                    <div class="contact-date-list__footer" x-show="upcomingContactAll().length > dateListLimit">
                        <a x-show="!showAllUpcoming" @click.prevent="showAllUpcoming = true">Show all</a>
                        <a x-show="showAllUpcoming" @click.prevent="showAllUpcoming = false">Show top 20</a>
                    </div>
                </div>
            </div>

            @include('filament.admin.partials.contact-board-quick-view-modal')
        </div>
    </div>
</x-filament-panels::page>
