<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdminModule;
use App\Enums\CargoStatus;
use App\Enums\NoteStatus;
use App\Enums\OrderStatus;
use App\Enums\TodoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Filament\Admin\Resources\Colors\ColorResource;
use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Notes\NoteResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Cargo;
use App\Models\Document;
use App\Models\Color;
use App\Models\DirectoryContact;
use App\Models\Invoice;
use App\Models\Note;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Todo;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use UnitEnum;

class Dashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'dashboard';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.admin.pages.dashboard';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    /**
     * @return Collection<int, array{todo: Todo, late: bool, approaching: bool, badge: ?string, url: string}>
     */
    public function getTasks(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Tasks)) {
            return collect();
        }

        return Todo::query()
            ->notArchived()
            ->where('status', '!=', TodoStatus::Done)
            ->whereNotNull('deadline')
            ->with(['responsible', 'project'])
            ->orderBy('deadline')
            ->limit(20)
            ->get()
            ->map(function (Todo $todo): array {
                $deadline = $todo->deadline;
                $late = $deadline !== null && $deadline->lt(now());
                $approaching = ! $late && $deadline !== null && $deadline->lte(now()->addDays(2));

                return [
                    'todo' => $todo,
                    'late' => $late,
                    'approaching' => $approaching,
                    'badge' => $late ? 'Late' : ($approaching ? 'Soon' : null),
                    'url' => TaskResource::getUrl('edit', ['record' => $todo]),
                ];
            });
    }

    /**
     * Documents waiting for the current user's approval or Dokobit signature.
     *
     * @return Collection<int, array{
     *     document: Document,
     *     url: string,
     *     badge: string,
     *     note: string,
     *     meta: ?string,
     *     pending: bool
     * }>
     */
    public function getMissingApprovals(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Documents)) {
            return collect();
        }

        $user = auth()->user();
        if ($user === null) {
            return collect();
        }

        $userId = (int) $user->getKey();

        return Document::query()
            ->where('flag_approved', false)
            ->with([
                'documentType',
                'approvers',
                'paymentReport.pendingApprovers',
                'dividendPaymentReport.pendingApprovers',
                'documentSigning.signers',
                'contractSigning.signers',
            ])
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->filter(function (Document $document) use ($user, $userId): bool {
                return $document->currentUserHasPendingApproval($userId)
                    || $document->currentUserCanSign($user);
            })
            ->take(20)
            ->map(function (Document $document) use ($user, $userId): array {
                $needsApproval = $document->currentUserHasPendingApproval($userId);
                $needsSignature = $document->currentUserCanSign($user);

                $badge = match (true) {
                    $needsApproval && $needsSignature => 'Approve & sign',
                    $needsSignature => 'Signature',
                    default => 'Approval',
                };

                return [
                    'document' => $document,
                    'url' => DocumentResource::getUrl('edit', ['record' => $document]),
                    'badge' => $badge,
                    'note' => $document->documentType?->name ?? '—',
                    'meta' => $document->document_date?->format('Y-m-d'),
                    'pending' => true,
                ];
            })
            ->values();
    }

    /**
     * Current user's active sticky notes.
     *
     * @return Collection<int, array{note: Note, preview: string, url: string}>
     */
    public function getNotes(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Tasks)) {
            return collect();
        }

        $userId = auth()->id();
        if ($userId === null) {
            return collect();
        }

        return Note::query()
            ->forUser((int) $userId)
            ->active()
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (Note $note): array => [
                'note' => $note,
                'preview' => Str::limit(trim((string) ($note->body ?? '')), 80) ?: '—',
                'url' => NoteResource::getUrl('edit', ['record' => $note]),
            ]);
    }

    public function archiveNote(int $noteId): void
    {
        if (! $this->canSeeModule(AdminModule::Tasks)) {
            Notification::make()
                ->title('No access')
                ->danger()
                ->send();

            return;
        }

        $userId = auth()->id();
        $note = Note::query()
            ->forUser((int) $userId)
            ->find($noteId);

        if ($note === null) {
            Notification::make()
                ->title('Note not found')
                ->danger()
                ->send();

            return;
        }

        if ($note->status === NoteStatus::Archived) {
            return;
        }

        $note->archive();

        Notification::make()
            ->title('Note archived')
            ->success()
            ->send();
    }

    public function createNoteAction(): Action
    {
        return Action::make('createNote')
            ->label('Add note')
            ->modalHeading('Add note')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('md')
            ->visible(fn (): bool => $this->canSeeModule(AdminModule::Tasks))
            ->form([
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->label('Text')
                    ->rows(6),
            ])
            ->action(function (array $data): void {
                if (! $this->canSeeModule(AdminModule::Tasks)) {
                    Notification::make()
                        ->title('No access')
                        ->danger()
                        ->send();

                    return;
                }

                $userId = auth()->id();
                if ($userId === null) {
                    Notification::make()
                        ->title('Not signed in')
                        ->danger()
                        ->send();

                    return;
                }

                Note::query()->create([
                    'title' => trim((string) ($data['title'] ?? '')),
                    'body' => filled($data['body'] ?? null) ? (string) $data['body'] : null,
                    'status' => NoteStatus::Active,
                    'user_id' => $userId,
                ]);

                Notification::make()
                    ->title('Note added')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return Collection<int, array{cargo: Cargo, label: string, late: bool, approaching: bool, badge: ?string, url: string}>
     */
    public function getIncomingCargos(): Collection
    {
        if (! $this->canSeeModule(AdminModule::WarehouseShipping)) {
            return collect();
        }

        return Cargo::query()
            ->where('status', '!=', CargoStatus::Received)
            ->where('status', '!=', CargoStatus::Draft)
            ->with('supplier')
            ->orderByRaw('estimated_arrival is null')
            ->orderBy('estimated_arrival')
            ->limit(5)
            ->get()
            ->map(function (Cargo $cargo): array {
                $arrival = $cargo->estimated_arrival;
                $days = $this->daysUntil($arrival);
                $late = $days !== null && $days < 0;
                $approaching = $days !== null && $days >= 0 && $days <= 2;

                return [
                    'cargo' => $cargo,
                    'label' => $this->arrivalLabel($arrival),
                    'late' => $late,
                    'approaching' => $approaching,
                    'badge' => $late ? 'Late' : ($approaching ? 'Soon' : null),
                    'url' => CargoResource::getUrl('edit', ['record' => $cargo]),
                ];
            });
    }

    /**
     * Contacts due for follow-up (next_contact set), overdue first — same idea as Contacts board.
     *
     * @return Collection<int, array{contact: DirectoryContact, late: bool, badge: ?string, url: string}>
     */
    public function getUpcomingContacts(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Contacts)) {
            return collect();
        }

        $today = now()->startOfDay();

        return DirectoryContact::query()
            ->whereNotNull('next_contact')
            ->orderBy('next_contact')
            ->limit(10)
            ->get()
            ->map(function (DirectoryContact $contact) use ($today): array {
                $next = $contact->next_contact?->copy()->startOfDay();
                $late = $next !== null && $next->lt($today);
                $dueToday = $next !== null && $next->equalTo($today);

                return [
                    'contact' => $contact,
                    'late' => $late,
                    'badge' => $late ? 'Late' : ($dueToday ? 'Today' : null),
                    'url' => DirectoryContactResource::getUrl('edit', ['record' => $contact]),
                ];
            });
    }

    /**
     * Most recently contacted companies — same idea as Contacts board "Last contacted".
     *
     * @return Collection<int, array{contact: DirectoryContact, url: string}>
     */
    public function getLastContacts(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Contacts)) {
            return collect();
        }

        return DirectoryContact::query()
            ->whereNotNull('last_contact')
            ->orderByDesc('last_contact')
            ->limit(10)
            ->get()
            ->map(fn (DirectoryContact $contact): array => [
                'contact' => $contact,
                'url' => DirectoryContactResource::getUrl('edit', ['record' => $contact]),
            ]);
    }

    /**
     * Latest orders (any status).
     *
     * @return Collection<int, array{order: Order, status: string, pending: bool, url: string}>
     */
    public function getNewOrders(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Orders)) {
            return collect();
        }

        return Order::query()
            ->with('user')
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Order $order): array => [
                'order' => $order,
                'status' => $order->status?->getLabel() ?? (string) $order->status?->value,
                'pending' => $order->status === OrderStatus::Pending,
                'url' => OrderResource::getUrl('edit', ['record' => $order]),
            ]);
    }

    /**
     * @return Collection<int, array{notification: DatabaseNotification, data: array<string, mixed>, unread: bool, badge: ?string}>
     */
    public function getNotifications(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $user->notifications()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($notification): array => [
                'notification' => $notification,
                'data' => (array) $notification->data,
                'unread' => $notification->read_at === null,
                'badge' => $notification->read_at === null ? 'New' : 'Viewed',
            ]);
    }

    public function dismissNotification(string $notificationId): void
    {
        $user = auth()->user();

        if ($user === null || blank($notificationId)) {
            return;
        }

        $notification = $user->notifications()->whereKey($notificationId)->first();

        if ($notification === null) {
            return;
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }
    }

    /**
     * Unpaid expense invoices + overdue contracts — payments we owe (I am late / missing).
     *
     * @return Collection<int, array{title: string, note: string, due: ?string, amount: string, late: bool, badge: string, url: string}>
     */
    public function getMyLatePayments(): Collection
    {
        if (! $this->canSeeFinancesPayments()) {
            return collect();
        }

        $invoiceRows = $this->outstandingInvoiceRows('expense');
        $contractRows = $this->outstandingContractRows();

        return $invoiceRows
            ->concat($contractRows)
            ->sort(function (array $left, array $right): int {
                if ($left['late'] !== $right['late']) {
                    return $left['late'] ? -1 : 1;
                }

                return strcmp((string) ($left['due'] ?? '9999-12-31'), (string) ($right['due'] ?? '9999-12-31'));
            })
            ->values()
            ->take(20);
    }

    /**
     * Unpaid income invoices — customers late / missing payment to us.
     *
     * @return Collection<int, array{title: string, note: string, due: ?string, amount: string, late: bool, badge: string, url: string}>
     */
    public function getCustomerLatePayments(): Collection
    {
        if (! $this->canSeeFinancesPayments()) {
            return collect();
        }

        return $this->outstandingInvoiceRows('income')
            ->sort(function (array $left, array $right): int {
                if ($left['late'] !== $right['late']) {
                    return $left['late'] ? -1 : 1;
                }

                return strcmp((string) ($left['due'] ?? '9999-12-31'), (string) ($right['due'] ?? '9999-12-31'));
            })
            ->values()
            ->take(20);
    }

    public function canSeeFinancesPayments(): bool
    {
        return $this->canSeeModule(AdminModule::Finances)
            || $this->canSeeModule(AdminModule::Reports);
    }

    public function latePaymentsUrl(): string
    {
        return ReportsLatePayments::getUrl();
    }

    /**
     * @param  'income'|'expense'  $kind
     * @return Collection<int, array{title: string, note: string, due: ?string, amount: string, late: bool, badge: string, url: string}>
     */
    private function outstandingInvoiceRows(string $kind): Collection
    {
        $query = Invoice::query()
            ->with('contact')
            ->where('paid', false)
            ->where('is_own_account', false);

        if ($kind === 'income') {
            $query->whereNotNull('income_type_id');
        } else {
            $query->whereNotNull('expense_type_id');
        }

        return $query
            ->orderByDesc('invoice_date')
            ->limit(200)
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->outstandingAmount() > 0.005)
            ->map(function (Invoice $invoice): array {
                $late = $invoice->isOverdue();
                $due = $invoice->dueDate();

                return [
                    'title' => $invoice->displayNumber(),
                    'note' => $invoice->contact?->company_name ?: '—',
                    'due' => $due?->toDateString(),
                    'amount' => Money::format($invoice->outstandingAmount()),
                    'late' => $late,
                    'badge' => $late ? 'Late' : 'Missing',
                    'url' => Invoices::getUrl(),
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array{title: string, note: string, due: ?string, amount: string, late: bool, badge: string, url: string}>
     */
    private function outstandingContractRows(): Collection
    {
        return Payment::query()
            ->with('contact')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (Payment $payment): ?array {
                if ($payment->isOneTime()) {
                    if ((bool) $payment->paid || $payment->due_date === null) {
                        return null;
                    }

                    $due = $payment->due_date;
                    $late = $payment->isOverdue();
                } else {
                    if ((float) $payment->amount <= 0.005) {
                        return null;
                    }

                    $due = $payment->nextDueDate();
                    if ($due === null) {
                        return null;
                    }

                    $late = $due->copy()->startOfDay()->lt(now()->startOfDay());
                    // Periodical: only surface overdue occurrences on the dashboard.
                    if (! $late) {
                        return null;
                    }
                }

                return [
                    'title' => $payment->name ?: ('Contract #'.$payment->getKey()),
                    'note' => $payment->contact?->company_name ?: 'Contract',
                    'due' => $due?->toDateString(),
                    'amount' => Money::format((float) $payment->amount),
                    'late' => $late,
                    'badge' => $late ? 'Late' : 'Missing',
                    'url' => PaymentResource::getUrl('edit', ['record' => $payment]),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{color: Color, meters: float, badge: string, url: ?string}>
     */
    public function getStockAlerts(): Collection
    {
        if (
            ! $this->canSeeModule(AdminModule::Reports)
            && ! $this->canSeeModule(AdminModule::WarehouseShipping)
            && ! $this->canSeeModule(AdminModule::Catalog)
        ) {
            return collect();
        }

        $metersSql = Color::stockMetersSqlExpression();
        $canOpenColor = $this->canSeeModule(AdminModule::Catalog);

        return Color::query()
            ->with('collection')
            ->belowRecommendedStock()
            ->select('colors.*')
            ->selectRaw("{$metersSql} as stock_meters_total")
            ->orderByRaw("{$metersSql} asc")
            ->limit(20)
            ->get()
            ->map(fn (Color $color): array => [
                'color' => $color,
                'meters' => (float) ($color->stock_meters_total ?? $color->stockMeters()),
                'badge' => 'Low',
                'url' => $canOpenColor
                    ? ColorResource::getUrl('edit', ['record' => $color])
                    : null,
            ]);
    }

    public function notificationsUrl(): string
    {
        return Notifications::getUrl();
    }

    public function lowStockUrl(): string
    {
        if (auth()->user()?->canAccessModule(AdminModule::Reports) ?? false) {
            return ReportsLowStock::getUrl();
        }

        return Stock::getUrl();
    }

    public function tasksUrl(): string
    {
        return TaskResource::getUrl('index');
    }

    public function createTaskUrl(): string
    {
        return TaskResource::getUrl('create');
    }

    public function notesUrl(): string
    {
        return NoteResource::getUrl('index');
    }

    public function createNoteUrl(): string
    {
        return NoteResource::getUrl('create');
    }

    public function cargosUrl(): string
    {
        return CargoResource::getUrl('index');
    }

    public function createCargoUrl(): string
    {
        return CargoResource::getUrl('create');
    }

    /**
     * @return array<int|string, string>
     */
    public function lowStockSupplierOptions(): array
    {
        return Color::query()
            ->belowRecommendedStock()
            ->with('collection.supplier')
            ->get()
            ->map(fn (Color $color): ?Supplier => $color->collection?->supplier)
            ->filter()
            ->unique(fn (Supplier $supplier): int => (int) $supplier->getKey())
            ->sortBy(fn (Supplier $supplier): string => mb_strtolower((string) $supplier->name))
            ->mapWithKeys(fn (Supplier $supplier): array => [
                (int) $supplier->getKey() => (string) $supplier->name,
            ])
            ->all();
    }

    public function createLowStockOrderAction(): Action
    {
        return Action::make('createLowStockOrder')
            ->label('Create new order')
            ->modalHeading('Create warehouse order')
            ->modalDescription('Opens a new warehouse order with low-stock products filled at each color’s default order amount.')
            ->modalSubmitActionLabel('Continue')
            ->modalWidth('md')
            ->visible(fn (): bool => $this->canSeeModule(AdminModule::WarehouseShipping))
            ->fillForm(function (): array {
                $options = $this->lowStockSupplierOptions();
                $defaultId = CargoResource::defaultSupplierId();

                if ($defaultId !== null && isset($options[(int) $defaultId])) {
                    return ['supplier_id' => (int) $defaultId];
                }

                $firstId = array_key_first($options);

                return [
                    'supplier_id' => $firstId !== null ? (int) $firstId : null,
                ];
            })
            ->form([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => $this->lowStockSupplierOptions() ?: Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data) {
                $supplierId = (int) ($data['supplier_id'] ?? 0);

                if ($supplierId <= 0) {
                    Notification::make()
                        ->title('Select a supplier')
                        ->warning()
                        ->send();

                    return;
                }

                return redirect()->to(
                    CargoResource::getUrl('create').'?'.http_build_query([
                        'supplier_id' => $supplierId,
                        'auto_order' => 1,
                    ])
                );
            });
    }

    public function incompleteDocumentsUrl(): string
    {
        return IncompleteDocuments::getUrl();
    }

    public function contactsUrl(): string
    {
        return ContactsBoard::getUrl();
    }

    public function ordersUrl(): string
    {
        return OrderResource::getUrl('index');
    }

    public function createOrderUrl(): string
    {
        return OrderResource::getUrl('create');
    }

    public function canSeeModule(AdminModule $module): bool
    {
        return auth()->user()?->canAccessModule($module) ?? false;
    }

    public function formatMoney(float|string|null $amount): string
    {
        return Money::format($amount);
    }

    public function formatMeters(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    public function daysUntil(?CarbonInterface $date): ?int
    {
        if ($date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
    }

    public function arrivalLabel(?CarbonInterface $date): string
    {
        $days = $this->daysUntil($date);

        if ($days === null) {
            return 'No ETA';
        }

        if ($days < 0) {
            $lateBy = abs($days);

            return $lateBy === 1 ? 'late 1 day' : "late {$lateBy} days";
        }

        if ($days === 0) {
            return 'today';
        }

        if ($days === 1) {
            return 'in 1 day';
        }

        return "in {$days} days";
    }
}
