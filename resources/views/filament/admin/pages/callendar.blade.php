<x-filament-panels::page>
    {{-- Keep Alpine/FullCalendar out of Livewire morph so save await/finally cannot get stuck. --}}
    <div wire:ignore class="space-y-4">
    <div
        x-data='callendarComponent()'
        x-init="initCalendar()"
        class="space-y-4"
    >
        <script type="application/json" x-ref="eventsJson">@json($this->getCalendarEvents())</script>
        <script type="application/json" x-ref="quickViewMetaJson">@json($this->getQuickViewMeta())</script>

        <script>
            function callendarComponent() {
                return {
            initialized: false,
            calendar: null,
            tooltipEl: null,
            events: [],
            allEvents: [],
            users: [],
            projects: [],
            currentUserId: null,
            currentUserLabel: "—",
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
                archived: false,
            },
            pendingUploadNames: [],
            uploadingAttachments: false,
            savingQuickView: false,
            filters: {
                financialsOnly: false,
                income: false,
                payments: false,
                statusNew: false,
                statusInprogress: false,
                statusConfirm: false,
                statusReturned: false,
                statusDone: false,
            },
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
                    archived: false,
                };
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
            toDateTimeLocalValue(date) {
                const pad = (n) => String(n).padStart(2, "0");
                return date.getFullYear()
                    + "-" + pad(date.getMonth() + 1)
                    + "-" + pad(date.getDate())
                    + "T" + pad(date.getHours())
                    + ":" + pad(date.getMinutes());
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
                    archived: Boolean(data.archived),
                };
                this.pendingUploadNames = [];
            },
            async openQuickViewFromEvent(event) {
                this.hideTooltip();
                const props = event.extendedProps ?? {};
                const todoId = Number(props.todo_id ?? event.id);
                if (! todoId) {
                    return;
                }

                this.quickViewMode = "edit";
                this.quickViewLoading = true;
                this.quickViewModalOpen = true;
                this.quickViewItem = {
                    id: todoId,
                    title: event.title ?? "",
                    can_edit: Boolean(props.can_edit),
                    user_label: props.user_label ?? props.user ?? "—",
                    edit_url: props.edit_url ?? null,
                };
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
                    window.alert("Could not load todo.");
                    this.closeQuickView();
                } finally {
                    this.quickViewLoading = false;
                }
            },
            async openCreateFromDateClick(info) {
                this.hideTooltip();

                const clicked = info.date instanceof Date ? info.date : new Date(info.date);
                let start;
                let end;

                if (info.allDay) {
                    start = new Date(clicked.getFullYear(), clicked.getMonth(), clicked.getDate(), 9, 0, 0);
                    end = new Date(clicked.getFullYear(), clicked.getMonth(), clicked.getDate(), 10, 0, 0);
                } else {
                    start = clicked;
                    end = new Date(clicked.getTime() + (60 * 60 * 1000));
                }

                this.quickViewMode = "create";
                this.quickViewItem = {
                    id: null,
                    title: "",
                    status: "new",
                    can_edit: true,
                    user_label: this.currentUserLabel,
                    edit_url: null,
                };
                this.quickViewForm = {
                    ...this.emptyQuickViewForm(),
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
                if (! todoId) {
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

                    this.allEvents = Array.isArray(result.events) ? result.events : [];
                    this.applyFilters();
                } catch (error) {
                    console.error(error);
                    window.alert("Could not archive task. Please try again.");
                }
            },
            async handleEventDrop(info) {
                this.hideTooltip();

                const todoId = Number(info.event.extendedProps?.todo_id ?? info.event.id);
                const canEdit = Boolean(info.event.extendedProps?.can_edit);

                if (! todoId || ! canEdit) {
                    info.revert();
                    return;
                }

                const wire = this.getLivewire();
                if (! wire) {
                    info.revert();
                    window.alert("Livewire connection not available");
                    return;
                }

                const startIso = info.event.start ? info.event.start.toISOString() : null;
                const endIso = info.event.end ? info.event.end.toISOString() : null;

                try {
                    const result = await wire.call("rescheduleTodo", todoId, startIso, endIso);
                    if (! result?.ok) {
                        info.revert();
                        return;
                    }

                    this.allEvents = Array.isArray(result.events) ? result.events : [];
                    this.applyFilters();
                } catch (error) {
                    console.error(error);
                    info.revert();
                    window.alert("Could not move task. Please try again.");
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

                    this.allEvents = Array.isArray(result.events) ? result.events : [];
                    this.applyFilters();
                    this.closeQuickView();
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
                if (! this.tooltipEl) {
                    return;
                }

                this.tooltipEl.style.display = "none";
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
            applyFilters() {
                if (! this.calendar) {
                    return;
                }

                const filtered = this.allEvents.filter((event) => {
                    const props = event.extendedProps ?? {};
                    const status = props.status ?? null;

                    if (this.filters.financialsOnly && ! props.has_financials) {
                        return false;
                    }
                    if (this.filters.income && ! props.has_income) {
                        return false;
                    }
                    if (this.filters.payments && ! props.has_payments) {
                        return false;
                    }

                    const statusFiltersActive = this.filters.statusNew
                        || this.filters.statusInprogress
                        || this.filters.statusConfirm
                        || this.filters.statusReturned
                        || this.filters.statusDone;
                    if (statusFiltersActive) {
                        const allowed = (
                            (this.filters.statusNew && status === "new")
                            || (this.filters.statusInprogress && status === "inprogress")
                            || (this.filters.statusConfirm && status === "confirm")
                            || (this.filters.statusReturned && status === "returned")
                            || (this.filters.statusDone && status === "done")
                        );
                        if (! allowed) {
                            return false;
                        }
                    }

                    return true;
                });

                this.calendar.removeAllEvents();
                this.calendar.addEventSource(filtered);
            },
            initCalendar() {
                if (this.initialized) {
                    return;
                }

                if (typeof FullCalendar === "undefined") {
                    setTimeout(() => this.initCalendar(), 100);
                    return;
                }

                const rawEvents = this.$refs.eventsJson?.textContent ?? "[]";
                this.events = JSON.parse(rawEvents);
                this.allEvents = this.events;

                try {
                    const metaRaw = this.$refs.quickViewMetaJson?.textContent ?? "{}";
                    const meta = JSON.parse(metaRaw);
                    this.users = Array.isArray(meta.users) ? meta.users : [];
                    this.projects = Array.isArray(meta.projects) ? meta.projects : [];
                    this.currentUserId = meta.current_user_id ?? null;
                    this.currentUserLabel = meta.current_user_label ?? "—";
                } catch (e) {
                    this.users = [];
                    this.projects = [];
                    this.currentUserId = null;
                    this.currentUserLabel = "—";
                }

                this.calendar = new FullCalendar.Calendar(this.$refs.calendar, {
                    initialView: "dayGridMonth",
                    height: "auto",
                    headerToolbar: {
                        left: "prev,next today",
                        center: "title",
                        right: "dayGridMonth,timeGridWeek,timeGridDay",
                    },
                    events: this.allEvents,
                    eventTimeFormat: {
                        hour: "2-digit",
                        minute: "2-digit",
                        hour12: false,
                    },
                    editable: true,
                    eventStartEditable: ({ event }) => Boolean(event.extendedProps?.can_edit),
                    eventDurationEditable: false,
                    eventDragStart: () => {
                        this.hideTooltip();
                    },
                    eventDrop: (info) => {
                        this.handleEventDrop(info);
                    },
                    dateClick: (info) => {
                        this.openCreateFromDateClick(info);
                    },
                    eventClick: (info) => {
                        info.jsEvent.preventDefault();
                        info.jsEvent.stopPropagation();

                        const todoId = Number(info.event.extendedProps?.todo_id ?? info.event.id);
                        if (info.jsEvent.ctrlKey || info.jsEvent.metaKey) {
                            this.archiveTodo(todoId);
                            return;
                        }

                        this.openQuickViewFromEvent(info.event);
                    },
                    eventContent: (arg) => {
                        const count = Number(arg.event.extendedProps.comments_count ?? 0);
                        const canEdit = Boolean(arg.event.extendedProps?.can_edit);
                        const wrap = document.createElement("div");
                        wrap.className = "fc-custom-event";
                        wrap.style.cursor = canEdit ? "grab" : "pointer";

                        const title = document.createElement("div");
                        title.className = "fc-custom-event__title";
                        title.textContent = arg.event.title ?? "";
                        wrap.appendChild(title);

                        if (count > 0) {
                            const badge = document.createElement("span");
                            badge.className = "fc-custom-event__comments";
                            badge.innerHTML = `
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M7.5 10.5H16.5M7.5 14.25H13.5M20.25 12C20.25 16.1421 16.5565 19.5 12 19.5C10.8305 19.5 9.71781 19.2796 8.70753 18.8828L4.5 20.25L5.75554 16.4812C4.96978 15.1961 4.5 13.6544 4.5 12C4.5 7.85786 8.19351 4.5 12 4.5C16.5565 4.5 20.25 7.85786 20.25 12Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>${count}</span>
                            `;
                            wrap.appendChild(badge);
                        }

                        return { domNodes: [wrap] };
                    },
                    eventDidMount: (info) => {
                        const eventTitle = info.event.title ?? "";
                        const description = (info.event.extendedProps.description ?? "").trim();
                        const totalIncome = info.event.extendedProps.total_income;
                        const incomeLeft = info.event.extendedProps.income_left;
                        const totalPayment = info.event.extendedProps.total_payment;
                        const paymentLeft = info.event.extendedProps.payment_left;
                        const hasFinance = [totalIncome, incomeLeft, totalPayment, paymentLeft]
                            .some((value) => value !== null && value !== undefined && value !== "");

                        const financeText = hasFinance
                            ? "Income: Total: "
                                + this.formatFinanceValue(totalIncome)
                                + " Left: "
                                + this.formatFinanceValue(incomeLeft)
                                + "\nExpences: Total: "
                                + this.formatFinanceValue(totalPayment)
                                + " Left: "
                                + this.formatFinanceValue(paymentLeft)
                            : "";

                        const tooltipText = [description, financeText]
                            .filter((part) => part !== "")
                            .join("\n");

                        const onMouseEnter = (event) => this.showTooltip(event, eventTitle, tooltipText);
                        const onMouseMove = (event) => this.moveTooltip(event);
                        const onMouseLeave = () => this.hideTooltip();

                        info.el.addEventListener("mouseenter", onMouseEnter);
                        info.el.addEventListener("mousemove", onMouseMove);
                        info.el.addEventListener("mouseleave", onMouseLeave);
                        info.el.style.cursor = Boolean(info.event.extendedProps?.can_edit) ? "grab" : "pointer";

                        info.event.setExtendedProp("_tooltipHandlers", {
                            onMouseEnter,
                            onMouseMove,
                            onMouseLeave,
                        });

                        const color = info.event.backgroundColor || info.event.borderColor;
                        const hasFinanceStroke = Boolean(info.event.extendedProps?.has_financials);
                        if (color) {
                            info.el.style.backgroundColor = color;
                            info.el.style.borderColor = hasFinanceStroke ? "#dc2626" : color;
                            info.el.style.borderWidth = hasFinanceStroke ? "3px" : "";
                            info.el.style.borderStyle = hasFinanceStroke ? "solid" : "";

                            const dot = info.el.querySelector(".fc-event-dot");
                            if (dot) {
                                dot.style.borderColor = color;
                            }
                        }
                    },
                    eventWillUnmount: (info) => {
                        const handlers = info.event.extendedProps._tooltipHandlers;
                        if (! handlers) {
                            return;
                        }

                        info.el.removeEventListener("mouseenter", handlers.onMouseEnter);
                        info.el.removeEventListener("mousemove", handlers.onMouseMove);
                        info.el.removeEventListener("mouseleave", handlers.onMouseLeave);
                    },
                });

                this.calendar.render();
                this.initialized = true;
            },
                };
            }
        </script>
        <div class="todo-calendar-filters rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center" style="column-gap: 12px; row-gap: 8px;">
                <label
                    class="todo-filter-chip todo-filter-chip--finance"
                    :class="{ 'is-active': filters.financialsOnly }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.financialsOnly" @change="applyFilters()" class="sr-only">
                    <span>Financials only</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--income"
                    :class="{ 'is-active': filters.income }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.income" @change="applyFilters()" class="sr-only">
                    <span>Income</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--payments"
                    :class="{ 'is-active': filters.payments }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.payments" @change="applyFilters()" class="sr-only">
                    <span>Expences</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--new"
                    :class="{ 'is-active': filters.statusNew }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.statusNew" @change="applyFilters()" class="sr-only">
                    <span>New</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--inprogress"
                    :class="{ 'is-active': filters.statusInprogress }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.statusInprogress" @change="applyFilters()" class="sr-only">
                    <span>In progress</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--confirm"
                    :class="{ 'is-active': filters.statusConfirm }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.statusConfirm" @change="applyFilters()" class="sr-only">
                    <span>Confirm</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--returned"
                    :class="{ 'is-active': filters.statusReturned }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.statusReturned" @change="applyFilters()" class="sr-only">
                    <span>Returned</span>
                </label>
                <label
                    class="todo-filter-chip todo-filter-chip--done"
                    :class="{ 'is-active': filters.statusDone }"
                    style="font-size: 12px; margin-right: 2px;"
                >
                    <input type="checkbox" x-model="filters.statusDone" @change="applyFilters()" class="sr-only">
                    <span>Done</span>
                </label>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div x-ref="calendar"></div>
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
            x-show="quickViewModalOpen"
            x-cloak
            class="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4"
            @keydown.escape.window="closeQuickView()"
        >
            <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900" @click.outside="closeQuickView()">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                        <span x-show="quickViewMode === 'create'">New task</span>
                        <span x-show="quickViewMode !== 'create'">
                            Task: <span x-text="quickViewItem?.display_title ?? quickViewItem?.title ?? ''"></span>
                        </span>
                    </h3>
                    <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" @click="closeQuickView()">✕</button>
                </div>

                <div class="space-y-3 overflow-y-auto p-4">
                    <div x-show="quickViewLoading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Loading...
                    </div>

                    <div x-show="!quickViewLoading" class="grid grid-cols-1 gap-3 md:grid-cols-2">
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
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Deadline</label>
                            <input type="datetime-local" x-model="quickViewForm.deadline" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Start date</label>
                            <input type="datetime-local" x-model="quickViewForm.start_date" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Description</label>
                            <textarea x-model="quickViewForm.description" rows="5" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)"></textarea>
                        </div>

                        <div class="md:col-span-2">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                                <input type="checkbox" x-model="quickViewForm.has_finances" class="rounded border-gray-300 text-primary-600" :disabled="!(quickViewItem?.can_edit)">
                                <span>Finances</span>
                            </label>
                        </div>

                        <div class="md:col-span-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700" x-show="quickViewForm.has_finances">
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Finances</div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Total income</label>
                                    <input type="number" min="0" step="0.01" x-model="quickViewForm.total_income" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Income left</label>
                                    <input type="number" min="0" step="0.01" x-model="quickViewForm.income_left" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Total expences</label>
                                    <input type="number" min="0" step="0.01" x-model="quickViewForm.total_payment" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Expences left</label>
                                    <input type="number" min="0" step="0.01" x-model="quickViewForm.payment_left" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                </div>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Watchers</label>
                            <select
                                multiple
                                x-model="quickViewForm.watcher_ids"
                                class="min-h-[7.5rem] w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                :disabled="!(quickViewItem?.can_edit)"
                            >
                                <template x-for="user in users" :key="user.id">
                                    <option :value="String(user.id)" x-text="user.label"></option>
                                </template>
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">Hold Ctrl/Cmd to select multiple.</p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Attachments</label>
                            <ul class="mb-2 space-y-1" x-show="(quickViewForm.attachments ?? []).length > 0">
                                <template x-for="(file, index) in quickViewForm.attachments" :key="'att-' + index + '-' + (file?.item_id || file?.name || file)">
                                    <li class="flex items-center justify-between gap-2 rounded-md border border-gray-200 px-2 py-1.5 text-xs dark:border-gray-700">
                                        <a :href="attachmentUrl(file)" target="_blank" class="truncate text-primary-600 underline" x-text="attachmentLabel(file)"></a>
                                        <button
                                            type="button"
                                            class="shrink-0 text-red-600 hover:underline"
                                            x-show="quickViewItem?.can_edit"
                                            @click="removeAttachment(index)"
                                        >Remove</button>
                                    </li>
                                </template>
                            </ul>
                            <ul class="mb-2 space-y-1" x-show="pendingUploadNames.length > 0">
                                <template x-for="(name, index) in pendingUploadNames" :key="'pending-' + index + '-' + name">
                                    <li class="rounded-md border border-dashed border-primary-300 px-2 py-1.5 text-xs text-primary-700 dark:border-primary-700 dark:text-primary-300">
                                        Pending upload: <span x-text="name"></span>
                                    </li>
                                </template>
                            </ul>
                            <input
                                type="file"
                                multiple
                                class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-1.5 file:text-sm file:font-medium dark:text-gray-300 dark:file:bg-gray-800"
                                :disabled="!(quickViewItem?.can_edit) || uploadingAttachments"
                                @change="onAttachmentsSelected($event)"
                            >
                            <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400" x-show="uploadingAttachments">Uploading...</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">User</label>
                            <select x-model="quickViewForm.user_id" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" :disabled="!(quickViewItem?.can_edit)">
                                <option value="">Select user</option>
                                <template x-for="user in users" :key="'owner-' + user.id">
                                    <option :value="String(user.id)" x-text="user.label"></option>
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

                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="calendar-quick-view-archived" x-model="quickViewForm.archived" class="rounded border-gray-300 text-primary-600" :disabled="!(quickViewItem?.can_edit)">
                            <label for="calendar-quick-view-archived" class="text-xs font-medium text-gray-600 dark:text-gray-300">Archived</label>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 dark:text-gray-400" x-show="!quickViewLoading && !(quickViewItem?.can_edit)">
                        Only the task author can edit this item.
                        <a x-show="quickViewItem?.edit_url" :href="quickViewItem?.edit_url" class="text-primary-600 underline">Open full edit page</a>
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-gray-200 p-4 dark:border-gray-700">
                    <button type="button" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800" @click="closeQuickView()">Close</button>
                    <button type="button" class="rounded-md bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-60" :disabled="savingQuickView || quickViewLoading || uploadingAttachments || !(quickViewItem?.can_edit)" @click="saveQuickView()">
                        <span x-show="!savingQuickView && quickViewMode === 'create'">Create task</span>
                        <span x-show="!savingQuickView && quickViewMode !== 'create'">Save changes</span>
                        <span x-show="savingQuickView">Saving...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div>
</x-filament-panels::page>
