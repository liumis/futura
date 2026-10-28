<x-filament-panels::page>
    <style>
        .dash-board {
            color: #222222;
            font-family: 'Jost', sans-serif;
            font-weight: 400;
        }
        .dash-board .mono,
        .dash-col__title,
        .dash-col__count,
        .dash-row__meta,
        .dash-row__badge,
        .dash-col__empty,
        .dash-col__footer a,
        .dash-col__footer button {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .dash-board-cols {
            display: flex;
            gap: 28px;
            align-items: stretch;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        .dash-col {
            display: flex;
            min-width: 260px;
            min-height: 420px;
            flex: 1 0 260px;
            flex-direction: column;
            border: 1px solid #C9C9C9;
            background-color: #FFFFFF;
            padding: 12px 16px 14px;
        }
        .dash-col__header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            border-bottom: 1px solid #222222;
            padding: 0 0 6px;
            margin-bottom: 2px;
            position: relative;
        }
        .dash-col__header::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -3px;
            width: 42px;
            border-bottom: 2px solid #222222;
        }
        .dash-col__title {
            font-size: 10px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .dash-col__count {
            font-size: 9px;
            font-weight: 400;
            color: #6B6B6B;
        }
        .dash-col__body {
            display: flex;
            flex: 1;
            flex-direction: column;
            min-height: 0;
            overflow-y: auto;
        }
        .dash-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #F2F2F2;
            padding: 9px 0;
            text-decoration: none;
            color: inherit;
            cursor: pointer;
        }
        .dash-row:hover {
            background-color: #FFFFFF;
        }
        .dash-row__main {
            min-width: 0;
            flex: 1;
        }
        .dash-row__title {
            font-size: 14px;
            font-weight: 400;
            color: #222222;
            line-height: 1.45;
            text-transform: uppercase;
            overflow-wrap: anywhere;
        }
        .dash-row__note {
            margin-top: 2px;
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6B6B6B;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .dash-row__side {
            display: flex;
            flex-shrink: 0;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            padding-top: 2px;
        }
        .dash-row__meta {
            font-size: 8px;
            letter-spacing: 0.12em;
            color: #6B6B6B;
            white-space: nowrap;
        }
        .dash-row__badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 8px;
            letter-spacing: 0.12em;
            color: #6B6B6B;
            white-space: nowrap;
        }
        .dash-row__badge-mark {
            display: inline-flex;
            width: 12px;
            height: 12px;
            align-items: center;
            justify-content: center;
            border: 1px solid currentColor;
            border-radius: 999px;
            font-size: 8px;
            line-height: 1;
            font-weight: 500;
        }
        .dash-row--late .dash-row__title,
        .dash-row--late .dash-row__note,
        .dash-row--late .dash-row__meta,
        .dash-row--late .dash-row__badge {
            color: #222222;
            font-weight: 500;
        }
        .dash-row--late .dash-row__badge-mark {
            color: #EB8027;
            border-color: #EB8027;
        }
        .dash-row__status {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 8px;
            font-weight: 500;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #222222;
            white-space: nowrap;
        }
        .dash-row--pending .dash-row__status::before {
            content: '';
            display: inline-block;
            width: 5px;
            height: 5px;
            margin-right: 6px;
            border-radius: 999px;
            background: #EB8027;
            vertical-align: middle;
        }
        .dash-row--alert .dash-row__title,
        .dash-row--alert .dash-row__note,
        .dash-row--alert .dash-row__badge {
            color: #222222;
            font-weight: 500;
        }
        .dash-row--alert .dash-row__badge-mark {
            color: #EB8027;
            border-color: #EB8027;
        }
        .dash-row--soon .dash-row__badge {
            color: #6B6B6B;
        }
        .dash-col__empty {
            padding: 28px 0;
            text-align: center;
            font-size: 9px;
            color: #6B6B6B;
        }
        .dash-col__footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: auto;
            padding-top: 10px;
            border-top: 1px solid #F2F2F2;
        }
        .dash-col__footer a,
        .dash-col__footer button {
            font-size: 9px;
            color: #6B6B6B;
            text-decoration: none;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-family: inherit;
            letter-spacing: inherit;
            text-transform: inherit;
        }
        .dash-col__footer a:hover,
        .dash-col__footer button:hover {
            color: #222222;
        }
        .dash-col--stack {
            padding: 0;
            gap: 12px;
            overflow: visible;
            border: none;
            background: transparent;
        }
        .dash-stack-block {
            display: flex;
            flex: 1 1 auto;
            flex-direction: column;
            min-height: 0;
            margin-top: 12px;
            padding: 12px 16px 14px;
            border: 1px solid #C9C9C9;
            background-color: #FFFFFF;
        }
        .dash-stack-block:first-child {
            margin-top: 0;
        }
        .dash-stack-block--cargos {
            flex: 0 0 auto;
        }
        .dash-stack-block--missing-approvals {
            flex: 0 0 auto;
            min-height: 120px;
        }
        .dash-stack-block--tasks,
        .dash-stack-block--notes {
            flex: 1 1 auto;
            min-height: 140px;
        }
        .dash-stack-block--contacts,
        .dash-stack-block--last-contacts {
            flex: 1 1 auto;
            min-height: 140px;
        }
        .dash-stack-block--notifications,
        .dash-stack-block--my-late,
        .dash-stack-block--customer-late {
            flex: 1 1 auto;
            min-height: 120px;
        }
        .dash-stack-block .dash-col__body {
            flex: 1;
        }
        .dash-stack-block .dash-col__footer {
            margin-top: 8px;
        }
        .dash-row__archive {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 6px;
            border: 1px solid #C9C9C9;
            background: #FFFFFF;
            color: #6B6B6B;
            font-size: 9px;
            font-family: inherit;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            cursor: pointer;
            line-height: 1.2;
        }
        .dash-row__archive:hover {
            color: #222222;
            border-color: #222222;
        }
        .dash-row__dismiss {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            padding: 0;
            border: none;
            background: transparent;
            color: #6B6B6B;
            font-size: 14px;
            font-family: inherit;
            line-height: 1;
            cursor: pointer;
        }
        .dash-row__dismiss:hover {
            color: #222222;
        }
        .dash-row__badge--viewed {
            color: #6B6B6B;
        }
        .dash-row--viewed .dash-row__title,
        .dash-row--viewed .dash-row__note,
        .dash-row--viewed .dash-row__meta {
            color: #6B6B6B;
        }
        .dash-balance-warnings {
            margin-bottom: 16px;
            border: 1px solid #C9C9C9;
            border-left: 3px solid #EB8027;
            background: #F2F2F2;
            padding: 12px 16px;
        }
        .dash-balance-warnings__title {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #222222;
            margin-bottom: 8px;
        }
        .dash-balance-warnings__list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .dash-balance-warnings__item {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px 16px;
            text-decoration: none;
            color: #222222;
            border-bottom: 1px solid #C9C9C9;
            padding-bottom: 6px;
        }
        .dash-balance-warnings__item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }
        .dash-balance-warnings__item:hover {
            color: #222222;
        }
        .dash-balance-warnings__month {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .dash-balance-warnings__meta {
            font-size: 12px;
            color: #6B6B6B;
        }
        .dash-balance-warnings__diff {
            font-family: 'Jost', ui-sans-serif, system-ui, sans-serif;
            font-size: 11px;
            letter-spacing: 0.06em;
            color: #222222;
            white-space: nowrap;
        }
    </style>

    @php
        $missingApprovals = $this->getMissingApprovals();
        $tasks = $this->getTasks();
        $notes = $this->getNotes();
        $cargos = $this->getIncomingCargos();
        $contacts = $this->getUpcomingContacts();
        $lastContacts = $this->getLastContacts();
        $orders = $this->getNewOrders();
        $notifications = $this->getNotifications();
        $myLatePayments = $this->getMyLatePayments();
        $customerLatePayments = $this->getCustomerLatePayments();
        $stock = $this->getStockAlerts();
        $unbalancedMonths = $this->getUnbalancedMonths();
        $canDocuments = $this->canSeeModule(\App\Enums\AdminModule::Documents);
        $canTasks = $this->canSeeModule(\App\Enums\AdminModule::Tasks);
        $canCargos = $this->canSeeModule(\App\Enums\AdminModule::WarehouseShipping);
        $canContacts = $this->canSeeModule(\App\Enums\AdminModule::Contacts);
        $canOrders = $this->canSeeModule(\App\Enums\AdminModule::Orders);
        $canPayments = $this->canSeeFinancesPayments();
        $canStock = $canCargos
            || $this->canSeeModule(\App\Enums\AdminModule::Reports)
            || $this->canSeeModule(\App\Enums\AdminModule::Catalog);
    @endphp

    <div class="dash-board">
        @if ($unbalancedMonths->isNotEmpty())
            <div class="dash-balance-warnings" role="status">
                <div class="dash-balance-warnings__title">Unbalanced months (debit vs credit)</div>
                <div class="dash-balance-warnings__list">
                    @foreach ($unbalancedMonths as $row)
                        <a href="{{ $row['url'] }}" class="dash-balance-warnings__item">
                            <div>
                                <div class="dash-balance-warnings__month">{{ $row['title'] }}</div>
                                <div class="dash-balance-warnings__meta">
                                    @if ($row['kind'] === 'own_account')
                                        Own-account transfers · debit {{ $this->formatMoney($row['debit']) }} · credit {{ $this->formatMoney($row['credit']) }}
                                    @else
                                        Invoice journals · debit {{ $this->formatMoney($row['debit']) }} · credit {{ $this->formatMoney($row['credit']) }}
                                    @endif
                                </div>
                            </div>
                            <div class="dash-balance-warnings__diff">Δ {{ $row['difference_label'] }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="dash-board-cols">
            {{-- Missing approvals + Tasks + Notes (stacked) --}}
            <section class="dash-col dash-col--stack">
                <div class="dash-stack-block dash-stack-block--missing-approvals">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Missing approvals</span>
                        <span class="dash-col__count">{{ $canDocuments ? $missingApprovals->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($missingApprovals as $row)
                            @php($document = $row['document'])
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--pending' => $row['pending'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $document->name }}</div>
                                    <div class="dash-row__note">{{ $row['note'] }}</div>
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $row['meta'] ?? '—' }}</span>
                                    <span class="dash-row__badge">
                                        <span class="dash-row__badge-mark">!</span>
                                        {{ $row['badge'] }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canDocuments ? 'Nothing waiting for you' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canDocuments)
                        <div class="dash-col__footer">
                            <a href="{{ $this->incompleteDocumentsUrl() }}">View more</a>
                        </div>
                    @endif
                </div>

                <div class="dash-stack-block dash-stack-block--tasks">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Tasks</span>
                        <span class="dash-col__count">{{ $canTasks ? $tasks->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($tasks as $row)
                            @php($todo = $row['todo'])
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--late' => $row['late'],
                                    'dash-row--soon' => $row['approaching'] && ! $row['late'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $todo->title }}</div>
                                    @if ($todo->responsible || $todo->project)
                                        <div class="dash-row__note">
                                            {{ collect([$todo->responsible?->fullName(), $todo->project?->name])->filter()->implode(' · ') }}
                                        </div>
                                    @endif
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $todo->deadline?->format('Y-m-d') ?? '—' }}</span>
                                    @if ($row['badge'])
                                        <span class="dash-row__badge">
                                            @if ($row['late'])
                                                <span class="dash-row__badge-mark">!</span>
                                            @endif
                                            {{ $row['badge'] }}
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canTasks ? 'No open tasks' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canTasks)
                        <div class="dash-col__footer">
                            <a href="{{ $this->tasksUrl() }}">View more</a>
                        </div>
                    @endif
                </div>

                <div class="dash-stack-block dash-stack-block--notes">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Notes</span>
                        <span class="dash-col__count">{{ $canTasks ? $notes->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($notes as $row)
                            @php($note = $row['note'])
                            <div class="dash-row">
                                <a href="{{ $row['url'] }}" class="dash-row__main" style="text-decoration: none; color: inherit;">
                                    <div class="dash-row__title">{{ $note->title }}</div>
                                    <div class="dash-row__note">{{ $row['preview'] }}</div>
                                </a>
                                <div class="dash-row__side">
                                    <button
                                        type="button"
                                        class="dash-row__archive"
                                        wire:click="archiveNote({{ $note->getKey() }})"
                                        wire:confirm="Archive this note?"
                                    >
                                        Archive
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="dash-col__empty">{{ $canTasks ? 'No notes' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canTasks)
                        <div class="dash-col__footer">
                            <button type="button" wire:click="mountAction('createNote')">
                                Add note
                            </button>
                            <a href="{{ $this->notesUrl() }}">View more</a>
                        </div>
                    @endif
                </div>
            </section>

            {{-- Incoming cargos + upcoming contacts (stacked) --}}
            <section class="dash-col dash-col--stack">
                <div class="dash-stack-block dash-stack-block--cargos">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Incoming cargos</span>
                        <span class="dash-col__count">{{ $canCargos ? $cargos->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($cargos as $row)
                            @php($cargo = $row['cargo'])
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--late' => $row['late'],
                                    'dash-row--soon' => $row['approaching'] && ! $row['late'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">
                                        #{{ $cargo->getKey() }}
                                        @if ($cargo->supplier?->name)
                                            {{ $cargo->supplier->name }}
                                        @endif
                                    </div>
                                    <div class="dash-row__note">
                                        {{ $cargo->status?->getLabel() }}
                                        @if ($cargo->estimated_arrival)
                                            · {{ $cargo->estimated_arrival->format('Y-m-d') }}
                                        @endif
                                    </div>
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $row['label'] }}</span>
                                    @if ($row['badge'])
                                        <span class="dash-row__badge">
                                            @if ($row['late'])
                                                <span class="dash-row__badge-mark">!</span>
                                            @endif
                                            {{ $row['badge'] }}
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canCargos ? 'No incoming cargos' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canCargos)
                        <div class="dash-col__footer">
                            <a href="{{ $this->cargosUrl() }}">View more</a>
                        </div>
                    @endif
                </div>

                <div class="dash-stack-block dash-stack-block--contacts">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Upcoming contact</span>
                        <span class="dash-col__count">{{ $canContacts ? $contacts->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($contacts as $row)
                            @php($contact = $row['contact'])
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--late' => $row['late'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $contact->company_name }}</div>
                                    @if ($contact->country || $contact->type)
                                        <div class="dash-row__note">
                                            {{ collect([$contact->country, $contact->type])->filter()->implode(' · ') }}
                                        </div>
                                    @endif
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $contact->next_contact?->format('Y-m-d') ?? '—' }}</span>
                                    @if ($row['badge'])
                                        <span class="dash-row__badge">
                                            @if ($row['late'])
                                                <span class="dash-row__badge-mark">!</span>
                                            @endif
                                            {{ $row['badge'] }}
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canContacts ? 'No contacts yet.' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canContacts)
                        <div class="dash-col__footer">
                            <a href="{{ $this->contactsUrl() }}">View more</a>
                        </div>
                    @endif
                </div>

                <div class="dash-stack-block dash-stack-block--last-contacts">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Last contacts</span>
                        <span class="dash-col__count">{{ $canContacts ? $lastContacts->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($lastContacts as $row)
                            @php($contact = $row['contact'])
                            <a href="{{ $row['url'] }}" class="dash-row">
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $contact->company_name }}</div>
                                    @if ($contact->country || $contact->type)
                                        <div class="dash-row__note">
                                            {{ collect([$contact->country, $contact->type])->filter()->implode(' · ') }}
                                        </div>
                                    @endif
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $contact->last_contact?->format('Y-m-d') ?? '—' }}</span>
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canContacts ? 'No contacts yet.' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canContacts)
                        <div class="dash-col__footer">
                            <a href="{{ $this->contactsUrl() }}">View more</a>
                        </div>
                    @endif
                </div>
            </section>

            {{-- Latest orders --}}
            <section class="dash-col">
                <header class="dash-col__header">
                    <span class="dash-col__title">Orders</span>
                    <span class="dash-col__count">{{ $canOrders ? $orders->count() : '—' }}</span>
                </header>
                <div class="dash-col__body">
                    @forelse ($orders as $row)
                        @php($order = $row['order'])
                        <a
                            href="{{ $row['url'] }}"
                            @class([
                                'dash-row',
                                'dash-row--pending' => $row['pending'],
                            ])
                        >
                            <div class="dash-row__main">
                                <div class="dash-row__title">
                                    {{ $order->user?->company_name ?: ($order->name ?: ('Order #'.$order->getKey())) }}
                                </div>
                                <div class="dash-row__note">
                                    #{{ $order->getKey() }}
                                    · {{ $this->formatMoney($order->amount) }}
                                </div>
                            </div>
                            <div class="dash-row__side">
                                <span class="dash-row__meta">
                                    {{ $order->order_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d') }}
                                </span>
                                <span class="dash-row__status">{{ $row['status'] }}</span>
                            </div>
                        </a>
                    @empty
                        <div class="dash-col__empty">{{ $canOrders ? 'No orders' : 'No access' }}</div>
                    @endforelse
                </div>
                @if ($canOrders)
                    <div class="dash-col__footer">
                        <a href="{{ $this->ordersUrl() }}">View more</a>
                    </div>
                @endif
            </section>

            {{-- Notifications + late payments (stacked) --}}
            <section class="dash-col dash-col--stack">
                <div class="dash-stack-block dash-stack-block--notifications">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Notifications</span>
                        <span class="dash-col__count">{{ $notifications->count() }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($notifications as $row)
                            @php($data = $row['data'])
                            @php($url = $data['url'] ?? $this->notificationsUrl())
                            @php($notification = $row['notification'])
                            <div
                                @class([
                                    'dash-row',
                                    'dash-row--alert' => $row['unread'],
                                    'dash-row--viewed' => ! $row['unread'],
                                ])
                            >
                                <a href="{{ $url }}" class="dash-row__main" style="text-decoration: none; color: inherit;">
                                    <div class="dash-row__title">
                                        {{ $data['title'] ?? $data['message'] ?? 'Notification' }}
                                    </div>
                                    @if (! empty($data['body']))
                                        <div class="dash-row__note">{{ \Illuminate\Support\Str::limit((string) $data['body'], 80) }}</div>
                                    @endif
                                </a>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $notification->created_at?->format('Y-m-d') }}</span>
                                    @if ($row['badge'])
                                        <span @class([
                                            'dash-row__badge',
                                            'dash-row__badge--viewed' => ! $row['unread'],
                                        ])>
                                            @if ($row['unread'])
                                                <span class="dash-row__badge-mark">!</span>
                                            @endif
                                            {{ $row['badge'] }}
                                        </span>
                                    @endif
                                    @if ($row['unread'])
                                        <button
                                            type="button"
                                            class="dash-row__dismiss"
                                            title="Mark as viewed"
                                            aria-label="Mark as viewed"
                                            wire:click="dismissNotification('{{ $notification->getKey() }}')"
                                        >
                                            ×
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="dash-col__empty">No notifications</div>
                        @endforelse
                    </div>
                    <div class="dash-col__footer">
                        <a href="{{ $this->notificationsUrl() }}">View more</a>
                    </div>
                </div>

                <div class="dash-stack-block dash-stack-block--my-late">
                    <header class="dash-col__header">
                        <span class="dash-col__title">My late payments</span>
                        <span class="dash-col__count">{{ $canPayments ? $myLatePayments->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($myLatePayments as $row)
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--late' => $row['late'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $row['title'] }}</div>
                                    <div class="dash-row__note">
                                        {{ $row['note'] }}
                                        · {{ $row['amount'] }}
                                    </div>
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $row['due'] ?? '—' }}</span>
                                    <span class="dash-row__badge">
                                        @if ($row['late'])
                                            <span class="dash-row__badge-mark">!</span>
                                        @endif
                                        {{ $row['badge'] }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canPayments ? 'No late payments' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canPayments)
                        <div class="dash-col__footer">
                            <a href="{{ $this->latePaymentsUrl() }}">View more</a>
                        </div>
                    @endif
                </div>

                <div class="dash-stack-block dash-stack-block--customer-late">
                    <header class="dash-col__header">
                        <span class="dash-col__title">Customer late payments</span>
                        <span class="dash-col__count">{{ $canPayments ? $customerLatePayments->count() : '—' }}</span>
                    </header>
                    <div class="dash-col__body">
                        @forelse ($customerLatePayments as $row)
                            <a
                                href="{{ $row['url'] }}"
                                @class([
                                    'dash-row',
                                    'dash-row--late' => $row['late'],
                                ])
                            >
                                <div class="dash-row__main">
                                    <div class="dash-row__title">{{ $row['title'] }}</div>
                                    <div class="dash-row__note">
                                        {{ $row['note'] }}
                                        · {{ $row['amount'] }}
                                    </div>
                                </div>
                                <div class="dash-row__side">
                                    <span class="dash-row__meta">{{ $row['due'] ?? '—' }}</span>
                                    <span class="dash-row__badge">
                                        @if ($row['late'])
                                            <span class="dash-row__badge-mark">!</span>
                                        @endif
                                        {{ $row['badge'] }}
                                    </span>
                                </div>
                            </a>
                        @empty
                            <div class="dash-col__empty">{{ $canPayments ? 'No late payments' : 'No access' }}</div>
                        @endforelse
                    </div>
                    @if ($canPayments)
                        <div class="dash-col__footer">
                            <a href="{{ $this->latePaymentsUrl() }}">View more</a>
                        </div>
                    @endif
                </div>
            </section>

            {{-- Stock alerts --}}
            <section class="dash-col">
                <header class="dash-col__header">
                    <span class="dash-col__title">Stock alerts</span>
                    <span class="dash-col__count">{{ $canStock ? $stock->count() : '—' }}</span>
                </header>
                <div class="dash-col__body">
                    @forelse ($stock as $row)
                        @php($color = $row['color'])
                        @php($tag = filled($row['url']) ? 'a' : 'div')
                        <{{ $tag }}
                            @if (filled($row['url'])) href="{{ $row['url'] }}" @endif
                            class="dash-row dash-row--late"
                        >
                            <div class="dash-row__main">
                                <div class="dash-row__title">
                                    {{ $color->color_name }}
                                    @if ($color->color_code)
                                        ({{ $color->color_code }})
                                    @endif
                                </div>
                                <div class="dash-row__note">
                                    {{ $color->collection?->name }}
                                    · {{ $this->formatMeters($row['meters']) }} / {{ (int) $color->recommended_stock }} m
                                </div>
                            </div>
                            <div class="dash-row__side">
                                <span class="dash-row__badge">
                                    <span class="dash-row__badge-mark">!</span>
                                    {{ $row['badge'] }}
                                </span>
                            </div>
                        </{{ $tag }}>
                    @empty
                        <div class="dash-col__empty">{{ $canStock ? 'No stock alerts' : 'No access' }}</div>
                    @endforelse
                </div>
                @if ($canStock)
                    <div class="dash-col__footer">
                        @if ($this->canSeeModule(\App\Enums\AdminModule::WarehouseShipping))
                            <button type="button" wire:click="mountAction('createLowStockOrder')">
                                Create new order
                            </button>
                        @else
                            <span></span>
                        @endif
                        <a href="{{ $this->lowStockUrl() }}">View more</a>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
