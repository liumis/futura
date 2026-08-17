<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="grid gap-4 sm:grid-cols-2 max-w-3xl">
                <div>
                    <label for="absence-calendar-employee" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Employee
                    </label>
                    <select
                        id="absence-calendar-employee"
                        wire:model.live="employeeId"
                        class="fi-select-input block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                        @foreach ($this->getEmployeeOptions() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="absence-calendar-status" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">
                        Status
                    </label>
                    <select
                        id="absence-calendar-status"
                        wire:model.live="status"
                        class="fi-select-input block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                    >
                        @foreach ($this->getStatusOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div
            wire:key="absence-calendar-{{ $employeeId }}-{{ $status ?? 'all' }}-{{ $calendarVersion }}"
            x-data="absenceCalendarComponent()"
            x-init="initCalendar()"
            class="space-y-4"
        >
            <script type="application/json" x-ref="calendarPayload">@json($this->getCalendarPayload())</script>

            <script>
                function absenceCalendarComponent() {
                    return {
                        initialized: false,
                        calendar: null,
                        payload: {},
                        tooltipEl: null,
                        escapeHtml(value) {
                            return (value ?? '')
                                .toString()
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .split(String.fromCharCode(39))
                                .join('&#039;');
                        },
                        ensureTooltip() {
                            if (this.tooltipEl) {
                                return this.tooltipEl;
                            }

                            const el = document.createElement('div');
                            el.className = 'absence-calendar-tooltip';
                            el.style.position = 'fixed';
                            el.style.zIndex = '9999';
                            el.style.display = 'none';
                            document.body.appendChild(el);
                            this.tooltipEl = el;

                            return el;
                        },
                        showTooltip(event, title, description) {
                            const el = this.ensureTooltip();
                            const safeTitle = this.escapeHtml(title);
                            const safeDescription = this.escapeHtml(description);
                            const descriptionHtml = safeDescription !== ''
                                ? '<div class="absence-calendar-tooltip__description">' + safeDescription + '</div>'
                                : '';

                            el.innerHTML = '<div class="absence-calendar-tooltip__title">' + safeTitle + '</div>' + descriptionHtml;
                            el.style.display = 'block';
                            this.moveTooltip(event);
                        },
                        moveTooltip(event) {
                            if (! this.tooltipEl || this.tooltipEl.style.display === 'none') {
                                return;
                            }

                            const offset = 14;
                            const maxLeft = window.innerWidth - this.tooltipEl.offsetWidth - 8;
                            const maxTop = window.innerHeight - this.tooltipEl.offsetHeight - 8;
                            const left = Math.min(event.clientX + offset, Math.max(8, maxLeft));
                            const top = Math.min(event.clientY + offset, Math.max(8, maxTop));

                            this.tooltipEl.style.left = left + 'px';
                            this.tooltipEl.style.top = top + 'px';
                        },
                        hideTooltip() {
                            if (! this.tooltipEl) {
                                return;
                            }

                            this.tooltipEl.style.display = 'none';
                        },
                        formatHours(value) {
                            const number = Number(value);
                            if (! Number.isFinite(number)) {
                                return '0';
                            }

                            return number.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
                        },
                        localDateKey(date) {
                            const year = date.getFullYear();
                            const month = String(date.getMonth() + 1).padStart(2, '0');
                            const day = String(date.getDate()).padStart(2, '0');

                            return year + '-' + month + '-' + day;
                        },
                        buildWorkHoursHtml(workDay) {
                            if (workDay.is_not_working_day && Number(workDay.hours) === 0 && Number(workDay.actual_hours) === 0) {
                                return '<div class="absence-calendar-hours__row absence-calendar-hours__row--off">'
                                    + '<span>Off day</span>'
                                    + '<button type="button" class="absence-calendar-hours__edit" data-edit-hours>Edit</button>'
                                    + '</div>';
                            }

                            return '<div class="absence-calendar-hours__row">'
                                + '<span class="absence-calendar-hours__label">Plan</span>'
                                + '<span class="absence-calendar-hours__planned">' + this.formatHours(workDay.hours) + ' h</span>'
                                + '</div>'
                                + '<div class="absence-calendar-hours__row">'
                                + '<span class="absence-calendar-hours__label">Act</span>'
                                + '<a href="#" class="absence-calendar-hours__actual absence-calendar-hours__actual-link" data-edit-hours>'
                                + this.formatHours(workDay.actual_hours) + ' h'
                                + '</a>'
                                + '</div>'
                                + '<div class="absence-calendar-hours__row absence-calendar-hours__row--actions">'
                                + '<button type="button" class="absence-calendar-hours__edit" data-edit-hours>Edit actuals</button>'
                                + '</div>';
                        },
                        openHoursEditor(dateKey) {
                            if (! dateKey) {
                                return;
                            }

                            this.hideTooltip();

                            const wire = this.$wire;
                            if (! wire) {
                                window.alert('Could not open hours editor. Please refresh the page.');
                                return;
                            }

                            if (typeof wire.openWorkDayHoursEditor === 'function') {
                                wire.openWorkDayHoursEditor(dateKey);
                                return;
                            }

                            if (typeof wire.mountAction === 'function') {
                                wire.mountAction('editWorkDayHours', { date: dateKey });
                                return;
                            }

                            window.alert('Could not open hours editor. Please refresh the page.');
                        },
                        renderWorkHours(info) {
                            const frame = info.el.querySelector('.fc-daygrid-day-frame');
                            if (! frame) {
                                return;
                            }

                            frame.querySelectorAll('.absence-calendar-hours').forEach((node) => node.remove());

                            const dateKey = info.el.getAttribute('data-date') ?? this.localDateKey(info.date);
                            const workDay = this.payload.workDays?.[dateKey];
                            if (! workDay) {
                                info.el.classList.remove('absence-calendar-day--editable');
                                return;
                            }

                            info.el.classList.add('absence-calendar-day--editable');
                            info.el.title = 'Click to edit plan and actual hours';

                            const hoursEl = document.createElement('div');
                            hoursEl.className = 'absence-calendar-hours';

                            if (Math.abs(Number(workDay.hours) - Number(workDay.actual_hours)) > 0.001) {
                                hoursEl.classList.add('absence-calendar-hours--variance');
                            }

                            hoursEl.innerHTML = this.buildWorkHoursHtml(workDay);

                            const openEditor = (event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                this.openHoursEditor(dateKey);
                            };

                            hoursEl.addEventListener('click', openEditor);
                            hoursEl.querySelectorAll('[data-edit-hours]').forEach((node) => {
                                node.addEventListener('click', openEditor);
                            });

                            const dayTop = frame.querySelector('.fc-daygrid-day-top');
                            if (dayTop) {
                                dayTop.insertAdjacentElement('afterend', hoursEl);
                            } else {
                                frame.prepend(hoursEl);
                            }
                        },
                        refreshAllWorkHourBadges() {
                            if (! this.calendar) {
                                return;
                            }

                            this.calendar.el.querySelectorAll('.fc-daygrid-day').forEach((dayEl) => {
                                const dateStr = dayEl.getAttribute('data-date');
                                if (! dateStr) {
                                    return;
                                }

                                const parts = dateStr.split('-');
                                const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));

                                this.renderWorkHours({ el: dayEl, date });
                            });
                        },
                        initCalendar() {
                            if (this.initialized) {
                                return;
                            }

                            if (typeof FullCalendar === 'undefined') {
                                setTimeout(() => this.initCalendar(), 100);
                                return;
                            }

                            const rawPayload = this.$refs.calendarPayload?.textContent ?? '{}';
                            this.payload = JSON.parse(rawPayload);

                            this.calendar = new FullCalendar.Calendar(this.$refs.calendar, {
                                initialView: 'dayGridMonth',
                                height: 'auto',
                                headerToolbar: {
                                    left: 'prev,next today',
                                    center: 'title',
                                    right: 'dayGridMonth,timeGridWeek,timeGridDay',
                                },
                                events: this.payload.leaveEvents ?? [],
                                eventTimeFormat: {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    hour12: false,
                                },
                                dateClick: (info) => {
                                    const dateKey = info.dateStr || this.localDateKey(info.date);
                                    this.openHoursEditor(dateKey);
                                },
                                dayCellDidMount: (info) => {
                                    this.renderWorkHours(info);
                                },
                                datesSet: () => {
                                    requestAnimationFrame(() => this.refreshAllWorkHourBadges());
                                },
                                eventContent: (arg) => {
                                    const wrap = document.createElement('div');
                                    wrap.className = 'fc-custom-event';

                                    const title = document.createElement('div');
                                    title.className = 'fc-custom-event__title';
                                    title.textContent = arg.event.title ?? '';
                                    wrap.appendChild(title);

                                    return { domNodes: [wrap] };
                                },
                                eventDidMount: (info) => {
                                    const eventTitle = info.event.title ?? '';
                                    const tooltipText = (info.event.extendedProps.comment ?? '').trim();

                                    const onMouseEnter = (event) => this.showTooltip(event, eventTitle, tooltipText);
                                    const onMouseMove = (event) => this.moveTooltip(event);
                                    const onMouseLeave = () => this.hideTooltip();

                                    info.el.addEventListener('mouseenter', onMouseEnter);
                                    info.el.addEventListener('mousemove', onMouseMove);
                                    info.el.addEventListener('mouseleave', onMouseLeave);

                                    info.event.setExtendedProp('_tooltipHandlers', {
                                        onMouseEnter,
                                        onMouseMove,
                                        onMouseLeave,
                                    });

                                    const color = info.event.backgroundColor || info.event.borderColor;
                                    if (color) {
                                        info.el.style.backgroundColor = color;
                                        info.el.style.borderColor = color;
                                    }
                                },
                                eventWillUnmount: (info) => {
                                    const handlers = info.event.extendedProps._tooltipHandlers;
                                    if (! handlers) {
                                        return;
                                    }

                                    info.el.removeEventListener('mouseenter', handlers.onMouseEnter);
                                    info.el.removeEventListener('mousemove', handlers.onMouseMove);
                                    info.el.removeEventListener('mouseleave', handlers.onMouseLeave);
                                },
                            });

                            this.calendar.render();
                            this.initialized = true;
                        },
                    };
                }
            </script>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div x-ref="calendar"></div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    Calculate annual leave
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Accrues {{ \App\Services\AnnualLeaveCalculator::DAYS_PER_YEAR }} working days per year from the earliest non-draft contract start,
                    minus confirmed / cancellation-pending
                    {{ \App\Services\AnnualLeaveCalculator::ANNUAL_LEAVE_TYPE_NAME }} working days up to the selected date.
                </p>

                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="w-full max-w-xs">
                        <label for="annual-leave-as-of" class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200">
                            Date
                        </label>
                        <input
                            id="annual-leave-as-of"
                            type="date"
                            wire:model="annualLeaveAsOf"
                            class="fi-input block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        />
                    </div>

                    <div>
                        <x-filament::button wire:click="calculateAnnualLeave" color="primary">
                            Calculate
                        </x-filament::button>
                    </div>
                </div>

                @if (is_array($annualLeaveResult))
                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
                        @if (! ($annualLeaveResult['ok'] ?? false))
                            <p class="text-sm text-warning-600 dark:text-warning-400">
                                {{ $annualLeaveResult['message'] ?? 'Could not calculate.' }}
                            </p>
                        @else
                            <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                                {{ number_format((float) $annualLeaveResult['available_days'], 2) }}
                                <span class="text-base font-medium text-gray-500 dark:text-gray-400">days available</span>
                            </div>
                            <dl class="mt-3 grid gap-2 text-sm text-gray-700 dark:text-gray-200 sm:grid-cols-2">
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Contract start</dt>
                                    <dd>{{ $annualLeaveResult['contract_start'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">As of</dt>
                                    <dd>{{ $annualLeaveResult['as_of'] ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Accrued</dt>
                                    <dd>{{ number_format((float) $annualLeaveResult['accrued_days'], 2) }} days</dd>
                                </div>
                                <div>
                                    <dt class="text-gray-500 dark:text-gray-400">Used (annual leave)</dt>
                                    <dd>{{ number_format((float) $annualLeaveResult['used_days'], 2) }} working days</dd>
                                </div>
                            </dl>
                        @endif
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">Legend</div>
                <div class="chart-legend">
                    <span class="chart-legend__item">
                        <span class="chart-legend__dot" style="background-color: #f8fafc; border: 1px solid #cbd5e1;"></span>
                        <span>Work hours (plan / act in day cell)</span>
                    </span>
                    <span class="chart-legend__item">
                        <span class="chart-legend__dot" style="background-color: #fef3c7; border: 1px solid #f59e0b;"></span>
                        <span>Plan vs actual mismatch</span>
                    </span>
                    @foreach ($this->getLeaveTypeLegend() as $item)
                        <span class="chart-legend__item">
                            <span class="chart-legend__dot" style="background-color: {{ $item['color'] }};"></span>
                            <span>{{ $item['name'] }}</span>
                        </span>
                    @endforeach
                </div>
                <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Each day with a timetable shows Plan and Act hours. Click <span class="font-medium">Act</span>,
                    <span class="font-medium">Edit actuals</span>, or the day cell to open the edit modal.
                    Colored bars are leave and overtime requests.
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
