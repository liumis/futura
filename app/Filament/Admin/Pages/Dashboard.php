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
use App\Models\Project;
use App\Models\Supplier;
use App\Models\Todo;
use App\Models\User;
use App\Services\TodoStatusWorkflow;
use App\Services\UnbalancedFinanceMonths;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set as SchemaSet;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;
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

    public function createTaskAction(): Action
    {
        return Action::make('createTask')
            ->label('New task')
            ->modalHeading('New task')
            ->modalSubmitActionLabel('Create')
            ->modalWidth('md')
            ->visible(fn (): bool => $this->canSeeModule(AdminModule::Tasks))
            ->form(fn (): array => $this->taskQuickFormSchema())
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

                $parsed = $this->parseTaskQuickFormData($data);
                if ($parsed === null) {
                    return;
                }

                Todo::query()->create([
                    ...$parsed,
                    'user_id' => $userId,
                    'status' => TodoStatus::New,
                    'archived' => false,
                ]);

                Notification::make()
                    ->title('Task created')
                    ->success()
                    ->send();
            });
    }

    public function editTaskAction(): Action
    {
        return Action::make('editTask')
            ->label('Edit task')
            ->modalHeading('Edit task')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('md')
            ->visible(fn (): bool => $this->canSeeModule(AdminModule::Tasks))
            ->fillForm(function (array $arguments): array {
                $todo = Todo::query()->find((int) ($arguments['todoId'] ?? 0));
                if ($todo === null) {
                    return [];
                }

                $status = $todo->status instanceof TodoStatus ? $todo->status : TodoStatus::New;
                $deadline = $todo->deadline instanceof CarbonInterface
                    ? $todo->deadline->toDateString()
                    : (is_string($todo->deadline) ? $todo->deadline : null);

                return [
                    'status' => $status->value,
                    'title' => $todo->title,
                    'project_id' => $todo->project_id,
                    'deadline' => $deadline,
                    'responsible_id' => $todo->responsible_id,
                    'description' => $todo->description,
                ];
            })
            ->form(fn (): array => [
                Hidden::make('status')
                    ->dehydrated(false)
                    ->live(),
                Placeholder::make('current_status')
                    ->label('Status')
                    ->content(function (Get $get): HtmlString {
                        $status = TodoStatusWorkflow::normalize($get('status')) ?? TodoStatus::New;
                        $label = e((string) ($status->getLabel() ?? $status->value));

                        return new HtmlString(
                            '<span style="display:inline-flex;align-items:center;border:1px solid #C9C9C9;padding:0.35rem 0.75rem;font-size:0.75rem;letter-spacing:0.12em;text-transform:uppercase;color:#222222;">'
                            .$label
                            .'</span>'
                        );
                    }),
                Actions::make([
                    Action::make('quickStatusBack')
                        ->label(function (Get $get): string {
                            $todo = $this->mountedEditTask();
                            if ($todo === null) {
                                return '← Back';
                            }

                            $back = TodoStatusWorkflow::stepBack(
                                $todo,
                                $get('status') ?? $todo->status,
                                auth()->user() instanceof User ? auth()->user() : null,
                            );

                            return $back ? '← '.$back->getLabel() : '← Back';
                        })
                        ->color('gray')
                        ->disabled(function (Get $get): bool {
                            $todo = $this->mountedEditTask();
                            if ($todo === null) {
                                return true;
                            }

                            return TodoStatusWorkflow::stepBack(
                                $todo,
                                $get('status') ?? $todo->status,
                                auth()->user() instanceof User ? auth()->user() : null,
                            ) === null;
                        })
                        ->action(function (Get $get, SchemaSet $set): void {
                            $this->applyQuickTaskStatusStep($get, $set, back: true);
                        }),
                    Action::make('quickStatusForward')
                        ->label(function (Get $get): string {
                            $todo = $this->mountedEditTask();
                            if ($todo === null) {
                                return 'Forward →';
                            }

                            $forward = TodoStatusWorkflow::stepForward(
                                $todo,
                                $get('status') ?? $todo->status,
                                auth()->user() instanceof User ? auth()->user() : null,
                            );

                            return $forward ? $forward->getLabel().' →' : 'Forward →';
                        })
                        ->color('primary')
                        ->disabled(function (Get $get): bool {
                            $todo = $this->mountedEditTask();
                            if ($todo === null) {
                                return true;
                            }

                            return TodoStatusWorkflow::stepForward(
                                $todo,
                                $get('status') ?? $todo->status,
                                auth()->user() instanceof User ? auth()->user() : null,
                            ) === null;
                        })
                        ->action(function (Get $get, SchemaSet $set): void {
                            $this->applyQuickTaskStatusStep($get, $set, back: false);
                        }),
                ])->key('quickStatus'),
                ...$this->taskQuickFormSchema(),
            ])
            ->action(function (array $data, array $arguments): void {
                if (! $this->canSeeModule(AdminModule::Tasks)) {
                    Notification::make()
                        ->title('No access')
                        ->danger()
                        ->send();

                    return;
                }

                $todo = Todo::query()->find((int) ($arguments['todoId'] ?? 0));
                if ($todo === null) {
                    Notification::make()
                        ->title('Task not found')
                        ->danger()
                        ->send();

                    return;
                }

                $parsed = $this->parseTaskQuickFormData($data);
                if ($parsed === null) {
                    return;
                }

                $todo->forceFill($parsed)->save();

                Notification::make()
                    ->title('Task updated')
                    ->success()
                    ->send();
            });
    }

    private function mountedEditTask(): ?Todo
    {
        $todoId = 0;

        foreach ($this->getMountedActions() as $action) {
            $todoId = (int) ($action->getArguments()['todoId'] ?? 0);
            if ($todoId > 0) {
                break;
            }
        }

        if ($todoId <= 0) {
            $todoId = (int) ($this->mountedActions[0]['arguments']['todoId'] ?? 0);
        }

        if ($todoId <= 0) {
            return null;
        }

        return Todo::query()->find($todoId);
    }

    private function applyQuickTaskStatusStep(Get $get, SchemaSet $set, bool $back): void
    {
        if (! $this->canSeeModule(AdminModule::Tasks)) {
            Notification::make()
                ->title('No access')
                ->danger()
                ->send();

            return;
        }

        $todo = $this->mountedEditTask();
        if ($todo === null) {
            Notification::make()
                ->title('Task not found')
                ->danger()
                ->send();

            return;
        }

        $actor = auth()->user() instanceof User ? auth()->user() : null;
        $from = $get('status') ?? $todo->status;
        $next = $back
            ? TodoStatusWorkflow::stepBack($todo, $from, $actor)
            : TodoStatusWorkflow::stepForward($todo, $from, $actor);

        if ($next === null) {
            return;
        }

        try {
            $todo->update(['status' => $next]);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not change status')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $set('status', $next->value);

        Notification::make()
            ->title('Status → '.$next->getLabel())
            ->success()
            ->send();
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function taskQuickFormSchema(): array
    {
        return [
            TextInput::make('title')
                ->label('Title')
                ->required()
                ->maxLength(255)
                ->autofocus(),
            Select::make('project_id')
                ->label('Project')
                ->options(fn (): array => Project::query()
                    ->where('archived', false)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Project $project): array => [
                        $project->getKey() => $project->label(),
                    ])
                    ->all())
                ->searchable()
                ->default(fn (): ?int => Project::defaultId())
                ->required(),
            DatePicker::make('deadline')
                ->label('Deadline')
                ->native(false)
                ->default(now()->addDay()->toDateString())
                ->required(),
            Select::make('responsible_id')
                ->label('Responsible')
                ->options(fn (): array => User::query()
                    ->orderBy('name')
                    ->orderBy('surname')
                    ->get()
                    ->mapWithKeys(fn (User $user): array => [
                        $user->getKey() => $user->fullName() !== ''
                            ? $user->fullName()
                            : (string) ($user->email ?? $user->getKey()),
                    ])
                    ->all())
                ->searchable()
                ->default(fn (): ?int => auth()->id())
                ->required(),
            Textarea::make('description')
                ->label('Description')
                ->rows(3),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: ?string, project_id: int, responsible_id: int, deadline: mixed}|null
     */
    private function parseTaskQuickFormData(array $data): ?array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $projectId = (int) ($data['project_id'] ?? 0);
        $responsibleId = (int) ($data['responsible_id'] ?? 0);

        if ($title === '' || $projectId <= 0 || $responsibleId <= 0) {
            Notification::make()
                ->title('Title, project and responsible are required')
                ->warning()
                ->send();

            return null;
        }

        return [
            'title' => $title,
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
            'project_id' => $projectId,
            'responsible_id' => $responsibleId,
            'deadline' => $data['deadline'] ?? now()->addDay()->toDateString(),
        ];
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
     * Newest CRM cards that have never been contacted (last_contact empty).
     *
     * @return Collection<int, array{contact: DirectoryContact, url: string}>
     */
    public function getNewNeverContactedContacts(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Contacts)) {
            return collect();
        }

        return DirectoryContact::query()
            ->whereNull('last_contact')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

    /**
     * Months where debit ≠ credit (invoice journals or own-account bank transfers).
     *
     * @return Collection<int, array{
     *     month_key: string,
     *     title: string,
     *     kind: 'journal'|'own_account',
     *     debit: float,
     *     credit: float,
     *     difference: float,
     *     difference_label: string,
     *     url: string,
     * }>
     */
    public function getUnbalancedMonths(): Collection
    {
        if (! $this->canSeeModule(AdminModule::Finances) && ! $this->canSeeModule(AdminModule::Reports)) {
            return collect();
        }

        return UnbalancedFinanceMonths::all()
            ->map(function (array $row): array {
                $row['url'] = match ($row['kind']) {
                    'own_account' => BankStatements::getUrl([
                        'filters' => [
                            'month' => $row['month_key'],
                            'direction' => 'own',
                        ],
                    ]),
                    default => ReportsDebitCredit::getUrl(),
                };

                return $row;
            });
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
