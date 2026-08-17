<x-filament-panels::page>
    <style>
        .todo-kanban-board {
            display: grid;
            grid-template-columns: repeat(5, minmax(220px, 1fr));
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 4px;
        }
        .todo-kanban-column {
            display: flex;
            min-height: 420px;
            flex-direction: column;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
            background-color: #f8fafc;
        }
        .todo-kanban-column__header {
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
        .todo-kanban-column__title {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        .todo-kanban-column__count {
            min-width: 1.5rem;
            border-radius: 9999px;
            background-color: #e5e7eb;
            padding: 2px 8px;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
        }
        .todo-kanban-column__body {
            display: flex;
            flex: 1;
            flex-direction: column;
            gap: 8px;
            padding: 10px;
            min-height: 360px;
            cursor: pointer;
        }
        .todo-kanban-column__body--empty::after {
                            content: "Click to add task";
            display: flex;
            flex: 1;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            border: 1px dashed #d1d5db;
            padding: 16px;
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
        }
        .kanban-card {
            display: flex;
            gap: 8px;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background-color: #fff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            cursor: grab;
        }
        .kanban-card:active { cursor: grabbing; }
        .kanban-card--ghost { opacity: 0.45; }
        .kanban-card--chosen { box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12); }
        .kanban-card__handle {
            display: flex;
            align-items: flex-start;
            padding: 10px 0 10px 8px;
            font-size: 12px;
            line-height: 1;
            color: #9ca3af;
            cursor: grab;
            user-select: none;
        }
        .kanban-card__handle:active { cursor: grabbing; }
        .kanban-card__body {
            flex: 1;
            padding: 10px 10px 10px 0;
            cursor: pointer;
        }
        .kanban-card__title {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            line-height: 1.35;
        }
        .kanban-card__meta {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-top: 6px;
            font-size: 11px;
            color: #6b7280;
        }
        .kanban-card__comments {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
            border-radius: 9999px;
            background-color: #f3f4f6;
            padding: 2px 8px;
            font-size: 11px;
            color: #374151;
        }
        .kanban-card__comments svg {
            width: 14px;
            height: 14px;
        }
        @media (max-width: 1100px) {
            .todo-kanban-board {
                grid-template-columns: repeat(5, 220px);
            }
        }
    </style>

    <div wire:ignore class="space-y-4">
        <div
            x-data="kanbanComponent()"
            x-init="init()"
            class="space-y-4"
        >
            <script type="application/json" x-ref="kanbanItemsJson">@json($this->getKanbanItems())</script>
            <script type="application/json" x-ref="kanbanColumnsJson">@json($this->getKanbanColumns())</script>
            <script type="application/json" x-ref="quickViewMetaJson">@json($this->getQuickViewMeta())</script>

            <script>
                function kanbanComponent() {
                    return {
                        initialized: false,
                        sortables: [],
                        items: [],
                        columns: [],
                        users: [],
                        projects: [],
                        currentUserId: null,
                        currentUserLabel: "—",
                        tooltipEl: null,
                        movingCard: false,
                        suppressCardClick: false,
                        quickViewMode: "edit",
                        quickViewModalOpen: false,
                        quickViewLoading: false,
                        quickViewItem: null,
                        quickViewForm: {
                            title: "",
                            project_id: "",
                            deadline: "",
                            start_date: "",
                            description: "",
                            has_finances: false,
                            total_income: "",
                            income_left: "",
                            total_payment: "",
                            payment_left: "",
                            watcher_ids: [],
                            attachments: [],
                            user_id: "",
                            status: "new",
                            priority: "regular",
                            archived: false,
                        },
                        filters: {
                            priorityHigh: false,
                            priorityRegular: false,
                            priorityLow: false,
                        },
                        pendingUploadNames: [],
                        uploadingAttachments: false,
                        savingQuickView: false,
                        emptyQuickViewForm() {
                            return {
                                title: "",
                                project_id: "",
                                deadline: "",
                                start_date: "",
                                description: "",
                                has_finances: false,
                                total_income: "",
                                income_left: "",
                                total_payment: "",
                                payment_left: "",
                                watcher_ids: [],
                                attachments: [],
                                user_id: this.currentUserId ? String(this.currentUserId) : "",
                                status: "new",
                                priority: "regular",
                                archived: false,
                            };
                        },
                        init() {
                            if (this.initialized) {
                                return;
                            }

                            try {
                                this.items = JSON.parse(this.$refs.kanbanItemsJson?.textContent ?? "[]");
                                this.columns = JSON.parse(this.$refs.kanbanColumnsJson?.textContent ?? "[]");
                                const meta = JSON.parse(this.$refs.quickViewMetaJson?.textContent ?? "{}");
                                this.users = Array.isArray(meta.users) ? meta.users : [];
                                this.projects = Array.isArray(meta.projects) ? meta.projects : [];
                                this.currentUserId = meta.current_user_id ?? null;
                                this.currentUserLabel = meta.current_user_label ?? "—";
                            } catch (error) {
                                console.error(error);
                                this.items = [];
                                this.columns = [];
                            }

                            this.initialized = true;
                            this.$nextTick(() => {
                                this.renderBoard();
                                this.initSortable();
                                this.initColumnClicks();
                            });
                        },
                        toDateTimeLocalValue(date) {
                            const pad = (n) => String(n).padStart(2, "0");
                            return date.getFullYear()
                                + "-" + pad(date.getMonth() + 1)
                                + "-" + pad(date.getDate())
                                + "T" + pad(date.getHours())
                                + ":" + pad(date.getMinutes());
                        },
                        initColumnClicks() {
                            this.columnBodies().forEach((body) => {
                                if (body.dataset.createClickBound === "1") {
                                    return;
                                }

                                body.dataset.createClickBound = "1";
                                body.addEventListener("click", (event) => {
                                    if (this.movingCard || this.suppressCardClick) {
                                        return;
                                    }

                                    if (event.target.closest(".kanban-card")) {
                                        return;
                                    }

                                    const status = body.dataset.status ?? "new";
                                    const hasItems = this.itemsForStatus(status).length > 0;
                                    if (hasItems) {
                                        return;
                                    }

                                    this.openCreateFromColumn(status);
                                });
                            });
                        },
                        itemsForStatus(status) {
                            return this.filteredItems().filter((item) => item.status === status);
                        },
                        filteredItems() {
                            const priorityFiltersActive = this.filters.priorityHigh
                                || this.filters.priorityRegular
                                || this.filters.priorityLow;

                            if (! priorityFiltersActive) {
                                return this.items;
                            }

                            return this.items.filter((item) => {
                                const priority = String(item.priority ?? "regular").toLowerCase();
                                return (
                                    (this.filters.priorityHigh && priority === "high")
                                    || (this.filters.priorityRegular && priority === "regular")
                                    || (this.filters.priorityLow && priority === "low")
                                );
                            });
                        },
                        applyFilters() {
                            this.renderBoard();
                            this.$nextTick(() => this.initSortable());
                        },
                        toggleFilter(key) {
                            this.filters[key] = ! this.filters[key];
                            this.applyFilters();
                        },
                        countForStatus(status) {
                            return this.itemsForStatus(status).length;
                        },
                        destroySortable() {
                            (this.sortables ?? []).forEach((sortable) => sortable.destroy());
                            this.sortables = [];
                        },
                        columnBodies() {
                            return Array.from(this.$el.querySelectorAll(".todo-kanban-column__body"));
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
                                        name: "todo-kanban",
                                        pull: true,
                                        put: true,
                                    },
                                    animation: 150,
                                    draggable: ".kanban-card",
                                    ghostClass: "kanban-card--ghost",
                                    chosenClass: "kanban-card--chosen",
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

                            const todoId = Number(evt.item?.dataset?.todoId ?? 0);
                            const toEl = evt.to?.closest?.(".todo-kanban-column__body") ?? evt.to;
                            const fromEl = evt.from?.closest?.(".todo-kanban-column__body") ?? evt.from;
                            const newStatus = toEl?.dataset?.status ?? "";
                            const oldStatus = fromEl?.dataset?.status ?? "";

                            if (! todoId || ! newStatus || newStatus === oldStatus) {
                                return;
                            }

                            const item = this.items.find((entry) => Number(entry.id) === todoId);
                            if (item) {
                                item.status = newStatus;
                            }

                            this.movingCard = true;
                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const result = await wire.call("moveTodoStatus", todoId, newStatus);
                                if (result?.ok && Array.isArray(result.items)) {
                                    this.items = result.items;
                                } else {
                                    if (item) {
                                        item.status = oldStatus;
                                    }
                                    if (result?.message) {
                                        window.alert(result.message);
                                    }
                                }
                            } catch (error) {
                                console.error(error);
                                if (item) {
                                    item.status = oldStatus;
                                }
                                window.alert("Could not move task. Please try again.");
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
                                const status = body.dataset.status ?? "";
                                const cards = this.itemsForStatus(status);
                                body.innerHTML = "";
                                body.classList.toggle("todo-kanban-column__body--empty", cards.length === 0);

                                cards.forEach((item) => {
                                    body.appendChild(this.createCardElement(item));
                                });
                            });
                        },
                        createCardElement(item) {
                            const card = document.createElement("div");
                            card.className = "kanban-card";
                            card.dataset.todoId = String(item.id);
                            if (item.has_financials) {
                                card.classList.add("kanban-card--finance");
                            }

                            const financeStroke = item.has_financials
                                ? "border-left: 3px solid #dc2626;"
                                : `border-left: 3px solid ${item.color ?? "#6b7280"};`;

                            card.innerHTML = `
                                <div class="kanban-card__handle" title="Drag to move">⋮⋮</div>
                                <div class="kanban-card__body">
                                    <div class="kanban-card__title">${this.escapeHtml(item.title ?? "")}</div>
                                    <div class="kanban-card__meta">
                                        <span>${this.escapeHtml(item.user_label ?? "—")}</span>
                                        <span>${this.escapeHtml(item.deadline_label ?? "—")}</span>
                                    </div>
                                    ${Number(item.comments_count ?? 0) > 0 ? `
                                        <div class="kanban-card__comments">
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M7.5 10.5H16.5M7.5 14.25H13.5M20.25 12C20.25 16.1421 16.5565 19.5 12 19.5C10.8305 19.5 9.71781 19.2796 8.70753 18.8828L4.5 20.25L5.75554 16.4812C4.96978 15.1961 4.5 13.6544 4.5 12C4.5 7.85786 8.19351 4.5 12 4.5C16.5565 4.5 20.25 7.85786 20.25 12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            <span>${Number(item.comments_count ?? 0)}</span>
                                        </div>
                                    ` : ""}
                                </div>
                            `;
                            card.style.cssText = financeStroke;

                            const body = card.querySelector(".kanban-card__body");
                            body?.addEventListener("click", (event) => {
                                if (this.suppressCardClick || this.movingCard) {
                                    return;
                                }

                                const todoId = Number(item.id);
                                if (event.ctrlKey || event.metaKey) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    this.archiveTodo(todoId);
                                    return;
                                }

                                this.openQuickView(todoId);
                            });
                            card.addEventListener("mouseenter", (event) => this.showCardTooltip(event, item));
                            card.addEventListener("mousemove", (event) => this.moveTooltip(event));
                            card.addEventListener("mouseleave", () => this.hideTooltip());

                            return card;
                        },
                        async openCreateFromColumn(status) {
                            this.hideTooltip();

                            const now = new Date();
                            const start = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 9, 0, 0);
                            const end = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 10, 0, 0);

                            this.quickViewMode = "create";
                            this.quickViewItem = {
                                id: null,
                                title: "",
                                status: status,
                                can_edit: true,
                                user_label: this.currentUserLabel,
                                edit_url: null,
                            };
                            this.quickViewForm = {
                                ...this.emptyQuickViewForm(),
                                status: status,
                                start_date: this.toDateTimeLocalValue(start),
                                deadline: this.toDateTimeLocalValue(end),
                            };
                            this.pendingUploadNames = [];
                            this.quickViewModalOpen = true;

                            try {
                                const wire = this.getLivewire();
                                if (wire) {
                                    await wire.call("resetQuickViewUploads");
                                }
                            } catch (error) {
                                console.error(error);
                            }
                        },
                        async openQuickView(todoId) {
                            if (! todoId || this.movingCard) {
                                return;
                            }

                            this.hideTooltip();
                            this.quickViewMode = "edit";
                            this.quickViewLoading = true;
                            this.quickViewModalOpen = true;
                            this.quickViewItem = { id: todoId };
                            this.quickViewForm = this.emptyQuickViewForm();

                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                await wire.call("resetQuickViewUploads");
                                const data = await wire.call("getTodoQuickView", todoId);
                                if (! data) {
                                    window.alert("Task not found.");
                                    this.closeQuickView();
                                    return;
                                }

                                this.fillFormFromTodo(data);
                            } catch (error) {
                                console.error(error);
                                window.alert("Could not load task.");
                                this.closeQuickView();
                            } finally {
                                this.quickViewLoading = false;
                            }
                        },
                        fillFormFromTodo(data) {
                            this.quickViewItem = {
                                id: data.id,
                                title: data.display_title ?? data.title ?? "",
                                display_title: data.display_title ?? data.title ?? "",
                                status: data.status ?? "new",
                                can_edit: Boolean(data.can_edit),
                                user_label: data.user_label ?? "—",
                                edit_url: data.edit_url ?? null,
                            };
                            this.quickViewForm = {
                                title: data.title ?? "",
                                project_id: data.project_id ? String(data.project_id) : "",
                                deadline: data.deadline ?? "",
                                start_date: data.start_date ?? "",
                                description: data.description ?? "",
                                has_finances: Boolean(data.has_finances),
                                total_income: data.total_income ?? "",
                                income_left: data.income_left ?? "",
                                total_payment: data.total_payment ?? "",
                                payment_left: data.payment_left ?? "",
                                watcher_ids: (data.watcher_ids ?? []).map((id) => String(id)),
                                attachments: Array.isArray(data.attachments) ? [...data.attachments] : [],
                                user_id: data.user_id ? String(data.user_id) : "",
                                status: data.status ?? "new",
                                priority: data.priority ?? "regular",
                                archived: Boolean(data.archived),
                            };
                            this.pendingUploadNames = [];
                        },
                        closeQuickView() {
                            this.quickViewModalOpen = false;
                            this.quickViewItem = null;
                            this.quickViewMode = "edit";
                            this.quickViewLoading = false;
                            this.savingQuickView = false;
                            this.uploadingAttachments = false;
                            this.pendingUploadNames = [];
                            this.quickViewForm = this.emptyQuickViewForm();
                        },
                        async archiveTodo(todoId) {
                            if (! todoId || this.movingCard) {
                                return;
                            }

                            this.hideTooltip();

                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const result = await wire.call("archiveTodo", todoId);
                                if (! result?.ok) {
                                    return;
                                }

                                if (Array.isArray(result.items)) {
                                    this.items = result.items;
                                }

                                this.renderBoard();
                                this.$nextTick(() => this.initSortable());
                            } catch (error) {
                                console.error(error);
                                window.alert("Could not archive task. Please try again.");
                            }
                        },
                        removeAttachment(index) {
                            this.quickViewForm.attachments.splice(index, 1);
                        },
                        async onAttachmentsSelected(event) {
                            const files = event.target.files;
                            if (! files || files.length === 0 || ! this.quickViewItem?.can_edit) {
                                return;
                            }

                            const wire = this.getLivewire();
                            if (! wire) {
                                window.alert("Livewire connection not available");
                                return;
                            }

                            this.uploadingAttachments = true;
                            try {
                                await new Promise((resolve, reject) => {
                                    wire.uploadMultiple(
                                        "quickViewUploads",
                                        files,
                                        () => resolve(),
                                        (error) => reject(error || new Error("Upload failed")),
                                        () => {},
                                    );
                                });
                                this.pendingUploadNames = [
                                    ...this.pendingUploadNames,
                                    ...Array.from(files).map((file) => file.name),
                                ];
                            } catch (error) {
                                console.error(error);
                                window.alert("Could not upload files. Please try again.");
                            } finally {
                                this.uploadingAttachments = false;
                                event.target.value = "";
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
                        buildPayload() {
                            return {
                                title: this.quickViewForm.title,
                                project_id: this.quickViewForm.project_id ? Number(this.quickViewForm.project_id) : null,
                                status: this.quickViewForm.status,
                                priority: this.quickViewForm.priority || "regular",
                                start_date: this.normalizeDateTime(this.quickViewForm.start_date),
                                deadline: this.normalizeDateTime(this.quickViewForm.deadline),
                                description: this.quickViewForm.description ?? "",
                                has_finances: Boolean(this.quickViewForm.has_finances),
                                total_income: this.quickViewForm.total_income,
                                income_left: this.quickViewForm.income_left,
                                total_payment: this.quickViewForm.total_payment,
                                payment_left: this.quickViewForm.payment_left,
                                watcher_ids: (this.quickViewForm.watcher_ids ?? []).map((id) => Number(id)),
                                attachments: this.quickViewForm.attachments ?? [],
                                user_id: this.quickViewForm.user_id ? Number(this.quickViewForm.user_id) : null,
                                archived: Boolean(this.quickViewForm.archived),
                            };
                        },
                        async saveQuickView() {
                            if (! this.quickViewItem || this.savingQuickView || this.quickViewLoading) {
                                return;
                            }
                            if (! this.quickViewItem.can_edit) {
                                return;
                            }

                            this.savingQuickView = true;
                            try {
                                const wire = this.getLivewire();
                                if (! wire) {
                                    throw new Error("Livewire connection not available");
                                }

                                const payload = this.buildPayload();
                                const result = this.quickViewMode === "create"
                                    ? await wire.call("createTodoQuickView", payload)
                                    : await wire.call(
                                        "updateTodoQuickView",
                                        Number(this.quickViewItem.id),
                                        payload,
                                    );

                                if (! result || ! result.ok) {
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
                                window.alert(
                                    this.quickViewMode === "create"
                                        ? "Could not create task. Please try again."
                                        : "Could not save task. Please try again."
                                );
                            } finally {
                                this.savingQuickView = false;
                            }
                        },
                        normalizeDateTime(value) {
                            if (! value) {
                                return null;
                            }

                            let normalized = String(value).trim().replace("T", " ");
                            if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(normalized)) {
                                normalized += ":00";
                            }

                            return normalized;
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
                        attachmentLabel(file) {
                            if (file && typeof file === "object") {
                                return file.name || "file";
                            }
                            const value = String(file ?? "");
                            const parts = value.split("/");
                            return parts[parts.length - 1] || value;
                        },
                        attachmentUrl(file) {
                            if (file && typeof file === "object") {
                                return file.url || "#";
                            }
                            const value = String(file ?? "").replace(/^\/+/, "");
                            if (! value) {
                                return "#";
                            }
                            if (value.startsWith("http://") || value.startsWith("https://")) {
                                return value;
                            }
                            return "/storage/" + value;
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
                        buildCardTooltip(item) {
                            const description = String(item.full_description ?? item.description ?? "").trim();
                            const hasFinance = Boolean(item.has_financials);
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

                            return [description, financeText].filter((part) => part !== "").join("\n");
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
                            const tooltipText = this.buildCardTooltip(item);
                            if (! tooltipText) {
                                return;
                            }

                            const el = this.ensureTooltip();
                            const safeTitle = this.escapeHtml(item.title ?? "");
                            const safeDescription = this.escapeHtml(tooltipText);
                            el.innerHTML = "<div class=\"todo-calendar-tooltip__title\">" + safeTitle + "</div>"
                                + "<div class=\"todo-calendar-tooltip__description\">" + safeDescription + "</div>";
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

            <div class="todo-calendar-filters mb-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                    <button type="button" class="todo-filter-chip todo-filter-chip--priority-high" :class="{ 'is-active': filters.priorityHigh }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityHigh')">High</button>
                    <button type="button" class="todo-filter-chip todo-filter-chip--priority-regular" :class="{ 'is-active': filters.priorityRegular }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityRegular')">Regular</button>
                    <button type="button" class="todo-filter-chip todo-filter-chip--priority-low" :class="{ 'is-active': filters.priorityLow }" style="font-size: 12px; margin-right: 2px;" @click="toggleFilter('priorityLow')">Low</button>
                </div>
            </div>

            <div class="todo-kanban-board">
                @foreach ($this->getKanbanColumns() as $column)
                    <div class="todo-kanban-column">
                        <div class="todo-kanban-column__header" style="border-top-color: {{ $column['color'] }}">
                            <span class="todo-kanban-column__title">{{ $column['label'] }}</span>
                            <span class="todo-kanban-column__count" x-text="countForStatus('{{ $column['value'] }}')"></span>
                        </div>
                        <div
                            class="todo-kanban-column__body"
                            data-status="{{ $column['value'] }}"
                        ></div>
                    </div>
                @endforeach
            </div>

            @include('filament.admin.partials.todo-quick-view-modal')
        </div>
    </div>
</x-filament-panels::page>
