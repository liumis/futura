<x-filament-panels::page>
    <style>
        .project-kanban-board {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(240px, 1fr);
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 4px;
        }
        .project-kanban-column {
            display: flex;
            min-height: 420px;
            flex-direction: column;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
            background-color: #f8fafc;
        }
        .project-kanban-column__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            border-top: 4px solid #6b7280;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 12px;
            background-color: #fff;
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
        }
        .project-kanban-column__title {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        .project-kanban-column__count {
            min-width: 1.5rem;
            border-radius: 9999px;
            background-color: #e5e7eb;
            padding: 2px 8px;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
        }
        .project-kanban-column__body {
            display: flex;
            flex: 1;
            flex-direction: column;
            gap: 8px;
            padding: 10px;
            min-height: 360px;
        }
        .project-kanban-card {
            display: flex;
            gap: 8px;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background-color: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            cursor: grab;
        }
        .project-kanban-card:active { cursor: grabbing; }
        .project-kanban-card--ghost { opacity: 0.45; }
        .project-kanban-card--chosen { box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12); }
        .project-kanban-card__handle {
            display: flex;
            align-items: flex-start;
            padding: 10px 0 10px 8px;
            font-size: 12px;
            line-height: 1;
            color: #9ca3af;
            cursor: grab;
            user-select: none;
        }
        .project-kanban-card__body {
            flex: 1;
            padding: 10px 10px 10px 0;
            cursor: pointer;
        }
        .project-kanban-card__title {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            line-height: 1.35;
        }
        .project-kanban-card__meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 6px;
            font-size: 11px;
            color: #6b7280;
        }
        .project-kanban-card__tasks {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 8px;
        }
        .project-kanban-card__task-count {
            border-radius: 9999px;
            background-color: #f3f4f6;
            padding: 2px 8px;
            font-size: 11px;
            color: #374151;
        }
    </style>

    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        {{ $this->form }}
    </div>

    @if ($this->selectedTypeId() === null)
        <p class="rounded-xl border border-dashed border-gray-200 bg-white px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            Select a project type to load the board.
        </p>
    @else
        <div wire:key="projects-board-{{ $this->selectedTypeId() }}" wire:ignore class="space-y-4">
            <div
                x-data="projectsBoardComponent()"
                x-init="init()"
                class="space-y-4"
            >
                <script type="application/json" x-ref="kanbanItemsJson">@json($this->getKanbanItems())</script>
                <script type="application/json" x-ref="kanbanColumnsJson">@json($this->getKanbanColumns())</script>

                <script>
                    function projectsBoardComponent() {
                        return {
                            initialized: false,
                            sortables: [],
                            items: [],
                            columns: [],
                            movingCard: false,
                            suppressCardClick: false,
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
                            },
                            itemsForStage(stage) {
                                return this.items.filter((item) => String(item.stage ?? "unassigned") === String(stage));
                            },
                            countForStage(stage) {
                                return this.itemsForStage(stage).length;
                            },
                            destroySortable() {
                                (this.sortables ?? []).forEach((sortable) => sortable.destroy());
                                this.sortables = [];
                            },
                            columnBodies() {
                                return Array.from(this.$el.querySelectorAll(".project-kanban-column__body"));
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
                                            name: "project-kanban",
                                            pull: true,
                                            put: true,
                                        },
                                        animation: 150,
                                        draggable: ".project-kanban-card",
                                        ghostClass: "project-kanban-card--ghost",
                                        chosenClass: "project-kanban-card--chosen",
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

                                const projectId = Number(evt.item?.dataset?.projectId ?? 0);
                                const toEl = evt.to?.closest?.(".project-kanban-column__body") ?? evt.to;
                                const fromEl = evt.from?.closest?.(".project-kanban-column__body") ?? evt.from;
                                const newStage = toEl?.dataset?.stage ?? "";
                                const oldStage = fromEl?.dataset?.stage ?? "";

                                if (! projectId || ! newStage || newStage === oldStage) {
                                    return;
                                }

                                const item = this.items.find((entry) => Number(entry.id) === projectId);
                                if (item) {
                                    item.stage = newStage;
                                }

                                this.movingCard = true;
                                try {
                                    const wire = this.getLivewire();
                                    if (! wire) {
                                        throw new Error("Livewire connection not available");
                                    }

                                    const result = await wire.call("moveProjectStage", projectId, newStage);
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
                                    window.alert("Could not move project. Please try again.");
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
                                    const cards = this.itemsForStage(stage);
                                    body.innerHTML = "";

                                    cards.forEach((item) => {
                                        body.appendChild(this.createCardElement(item));
                                    });
                                });
                            },
                            createCardElement(item) {
                                const card = document.createElement("div");
                                card.className = "project-kanban-card";
                                card.dataset.projectId = String(item.id);
                                card.style.borderLeft = "3px solid #6b7280";

                                const taskCounts = Array.isArray(item.task_counts) ? item.task_counts : [];
                                const taskHtml = taskCounts.length > 0
                                    ? `<div class="project-kanban-card__tasks">${taskCounts.map((row) =>
                                        `<span class="project-kanban-card__task-count">${this.escapeHtml(row.label ?? "")} - ${Number(row.count ?? 0)}</span>`
                                    ).join("")}</div>`
                                    : "";

                                card.innerHTML = `
                                    <div class="project-kanban-card__handle" title="Drag to move">⋮⋮</div>
                                    <div class="project-kanban-card__body">
                                        <div class="project-kanban-card__title">${this.escapeHtml(item.title ?? "")}</div>
                                        <div class="project-kanban-card__meta">
                                            <span>${this.escapeHtml(item.code ?? "")}</span>
                                        </div>
                                        ${taskHtml}
                                    </div>
                                `;

                                const body = card.querySelector(".project-kanban-card__body");
                                body?.addEventListener("click", () => {
                                    if (this.suppressCardClick || this.movingCard) {
                                        return;
                                    }

                                    if (item.edit_url) {
                                        window.location.href = item.edit_url;
                                    }
                                });

                                return card;
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
                        };
                    }
                </script>

                <div class="project-kanban-board">
                    @foreach ($this->getKanbanColumns() as $column)
                        <div class="project-kanban-column">
                            <div class="project-kanban-column__header" style="border-top-color: {{ $column['color'] }}">
                                <span class="project-kanban-column__title">{{ $column['label'] }}</span>
                                <span class="project-kanban-column__count" x-text="countForStage('{{ $column['value'] }}')"></span>
                            </div>
                            <div
                                class="project-kanban-column__body"
                                data-stage="{{ $column['value'] }}"
                            ></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
