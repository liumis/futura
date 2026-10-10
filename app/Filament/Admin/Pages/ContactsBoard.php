<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactComment;
use App\Models\DirectoryContactStage;
use App\Services\DirectoryContactExcelImporter;
use App\Support\UploadLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ContactsBoard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?string $slug = 'contacts-board';

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.contacts-board';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $filters = [];

    /**
     * @var list<string>
     */
    private const STAGE_COLORS = [
        '#2563eb',
        '#059669',
        '#d97706',
        '#7c3aed',
        '#db2777',
        '#0891b2',
        '#4f46e5',
        '#ca8a04',
    ];

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'filtersForm',
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public function mount(): void
    {
        $this->filtersForm->fill([
            'country' => null,
            'company_name' => null,
            'type' => null,
            'last_contact_from' => null,
            'last_contact_until' => null,
            'next_contact_from' => null,
            'next_contact_until' => null,
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Grid::make(3)
                    ->schema([
                        Select::make('country')
                            ->label('Country')
                            ->placeholder('All countries')
                            ->options(fn (): array => $this->countryOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->countryOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->countryOptionLabel($value))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live(),

                        Select::make('company_name')
                            ->label('Company name')
                            ->placeholder('All companies')
                            ->options(fn (): array => $this->companyOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->companyOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live(),

                        Select::make('type')
                            ->label('Type')
                            ->placeholder('All types')
                            ->options(fn (): array => $this->typeOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->typeOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->typeOptionLabel($value))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live(),
                    ]),

                Grid::make(4)
                    ->schema([
                        DatePicker::make('last_contact_from')
                            ->label('Last contact from')
                            ->native(false)
                            ->live(),

                        DatePicker::make('last_contact_until')
                            ->label('Last contact until')
                            ->native(false)
                            ->live(),

                        DatePicker::make('next_contact_from')
                            ->label('Next contact from')
                            ->native(false)
                            ->live(),

                        DatePicker::make('next_contact_until')
                            ->label('Next contact until')
                            ->native(false)
                            ->live(),
                    ]),
            ]);
    }

    public function updatedFilters(): void
    {
        $this->dispatch('contacts-board-filtered', items: $this->getKanbanItems());
    }

    /**
     * @return array<string, string>
     */
    public function countryOptions(?string $search = null): array
    {
        $query = DirectoryContact::query()
            ->selectRaw('country, COUNT(*) as contacts_count')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->groupBy('country')
            ->orderBy('country');

        if (filled($search)) {
            $query->where('country', 'like', '%'.$search.'%');
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->country => $this->formatCountryOptionLabel(
                    (string) $row->country,
                    (int) $row->contacts_count,
                ),
            ])
            ->all();
    }

    public function countryOptionLabel(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $country = (string) $value;
        $count = DirectoryContact::query()
            ->where('country', $country)
            ->count();

        if ($count <= 0) {
            return $country;
        }

        return $this->formatCountryOptionLabel($country, $count);
    }

    protected function formatCountryOptionLabel(string $country, int $count): string
    {
        return $country.' ('.$count.')';
    }

    /**
     * @return array<string, string>
     */
    public function companyOptions(?string $search = null): array
    {
        $query = DirectoryContact::query()
            ->orderBy('company_name');

        if (filled($search)) {
            $query->where('company_name', 'like', '%'.$search.'%');
        }

        return $query
            ->limit(50)
            ->pluck('company_name', 'company_name')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(?string $search = null): array
    {
        $query = DirectoryContact::query()
            ->selectRaw('type, COUNT(*) as contacts_count')
            ->whereNotNull('type')
            ->where('type', '!=', '')
            ->groupBy('type')
            ->orderBy('type');

        if (filled($search)) {
            $query->where('type', 'like', '%'.$search.'%');
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (string) $row->type => $this->formatTypeOptionLabel(
                    (string) $row->type,
                    (int) $row->contacts_count,
                ),
            ])
            ->all();
    }

    public function typeOptionLabel(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        $type = (string) $value;
        $count = DirectoryContact::query()
            ->where('type', $type)
            ->count();

        if ($count <= 0) {
            return $type;
        }

        return $this->formatTypeOptionLabel($type, $count);
    }

    protected function formatTypeOptionLabel(string $type, int $count): string
    {
        return $type.' ('.$count.')';
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('importExcel')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import contacts from Excel')
                ->modalDescription('Creates or updates contact cards from a Futura CRM spreadsheet.')
                ->modalSubmitActionLabel('Import')
                ->form([
                    UploadLimits::apply(
                        FileUpload::make('file')
                            ->label('Excel file')
                            ->acceptedFileTypes([
                                '.xlsx',
                                '.xls',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->disk('local')
                            ->directory('directory-contact-imports')
                            ->visibility('private')
                            ->required(),
                        'Futura CRM .xlsx with Company name column.',
                    ),
                ])
                ->action(function (array $data): void {
                    $path = $data['file'] ?? null;
                    if (is_array($path)) {
                        $path = $path[0] ?? null;
                    }
                    $relative = is_string($path) ? $path : '';
                    if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
                        Notification::make()->title('No Excel file selected')->danger()->send();

                        return;
                    }
                    $absolute = Storage::disk('local')->path($relative);
                    try {
                        $result = app(DirectoryContactExcelImporter::class)->import(
                            $absolute,
                            basename($relative),
                            auth()->id(),
                        );
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Could not import contacts')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } finally {
                        Storage::disk('local')->delete($relative);
                    }
                    Notification::make()
                        ->title('Contacts imported')
                        ->body(sprintf(
                            'Created %d, updated %d — cards appear on this board by Stage.',
                            $result['created'],
                            $result['updated'],
                        ))
                        ->success()
                        ->send();

                    $this->dispatch('contacts-board-filtered', items: $this->getKanbanItems());
                }),
            Action::make('newContact')
                ->label('New contact')
                ->icon('heroicon-o-plus')
                ->url(DirectoryContactResource::getUrl('create')),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function getKanbanColumns(): array
    {
        DirectoryContactStage::default();

        $columns = [];

        DirectoryContactStage::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(function (DirectoryContactStage $stage, int $index) use (&$columns): void {
                $columns[] = [
                    'value' => (string) $stage->getKey(),
                    'label' => $stage->name,
                    'color' => self::STAGE_COLORS[$index % count(self::STAGE_COLORS)],
                ];
            });

        return $columns;
    }

    /**
     * @return array{
     *     total: int,
     *     needs_contact: int,
     *     overdue: int,
     *     due_today: int,
     *     upcoming: int,
     *     never_contacted: int
     * }
     */
    public function getSummaryStats(): array
    {
        $today = now()->toDateString();
        $base = $this->filteredContactsQuery();

        $needsContact = (clone $base)
            ->whereNotNull('next_contact')
            ->whereDate('next_contact', '<=', $today)
            ->count();

        $overdue = (clone $base)
            ->whereNotNull('next_contact')
            ->whereDate('next_contact', '<', $today)
            ->count();

        $dueToday = (clone $base)
            ->whereDate('next_contact', $today)
            ->count();

        $upcoming = (clone $base)
            ->whereNotNull('next_contact')
            ->whereDate('next_contact', '>', $today)
            ->count();

        $neverContacted = (clone $base)
            ->whereNull('last_contact')
            ->count();

        return [
            'total' => (clone $base)->count(),
            'needs_contact' => $needsContact,
            'overdue' => $overdue,
            'due_today' => $dueToday,
            'upcoming' => $upcoming,
            'never_contacted' => $neverContacted,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getKanbanItems(): array
    {
        return $this->filteredContactsQuery()
            ->with(['latestComment', 'contactPersons'])
            ->withCount('comments')
            ->orderBy('company_name')
            ->get()
            ->map(fn (DirectoryContact $contact): array => $this->mapKanbanItem($contact))
            ->values()
            ->all();
    }

    protected function filteredContactsQuery(): Builder
    {
        $query = DirectoryContact::query();

        $country = trim((string) ($this->filters['country'] ?? ''));
        if ($country !== '') {
            $query->where('country', $country);
        }

        $company = trim((string) ($this->filters['company_name'] ?? ''));
        if ($company !== '') {
            $query->where('company_name', $company);
        }

        $type = trim((string) ($this->filters['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $this->applyDateRangeFilter($query, 'last_contact', $this->filters['last_contact_from'] ?? null, $this->filters['last_contact_until'] ?? null);
        $this->applyDateRangeFilter($query, 'next_contact', $this->filters['next_contact_from'] ?? null, $this->filters['next_contact_until'] ?? null);

        return $query;
    }

    protected function applyDateRangeFilter(Builder $query, string $column, mixed $from, mixed $until): void
    {
        $fromDate = $this->normalizeDate($from);
        $untilDate = $this->normalizeDate($until);

        if ($fromDate !== null) {
            $query->whereDate($column, '>=', $fromDate);
        }

        if ($untilDate !== null) {
            $query->whereDate($column, '<=', $untilDate);
        }
    }

    /**
     * @return array{ok: bool, items?: array<int, array<string, mixed>>, message?: string}
     */
    public function moveContactStage(int $contactId, string $stageKey): array
    {
        $contact = DirectoryContact::query()->find($contactId);

        if ($contact === null) {
            return ['ok' => false, 'message' => 'Contact not found.'];
        }

        $stageId = $this->resolveStageId($stageKey);

        if ($stageId === null) {
            return ['ok' => false, 'message' => 'Invalid stage.'];
        }

        $contact->update([
            'directory_contact_stage_id' => $stageId,
        ]);

        return [
            'ok' => true,
            'items' => $this->getKanbanItems(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContactQuickView(int $contactId): ?array
    {
        $contact = DirectoryContact::query()->find($contactId);

        if ($contact === null) {
            return null;
        }

        $comments = $contact->comments()
            ->orderByDesc('commented_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (DirectoryContactComment $comment): array => [
                'commented_at' => $comment->commented_at?->format('Y-m-d H:i') ?? '',
                'comment' => (string) $comment->comment,
            ])
            ->values()
            ->all();

        $persons = $contact->contactPersons()
            ->orderBy('id')
            ->get()
            ->map(fn ($person): array => [
                'name' => trim(implode(' ', array_filter([(string) $person->name, (string) $person->surname]))),
                'phone' => filled($person->phone) ? (string) $person->phone : '',
                'email' => filled($person->email) ? (string) $person->email : '',
                'comment' => filled($person->comment) ? (string) $person->comment : '',
            ])
            ->values()
            ->all();

        return [
            'id' => $contact->getKey(),
            'title' => $contact->company_name,
            'type' => (string) ($contact->type ?? ''),
            'phone' => (string) ($contact->phone ?? ''),
            'email' => (string) ($contact->email ?? ''),
            'last_contact' => $contact->last_contact?->format('Y-m-d') ?? '',
            'next_contact' => $contact->next_contact?->format('Y-m-d') ?? '',
            'edit_url' => DirectoryContactResource::getUrl('edit', ['record' => $contact]),
            'comments' => $comments,
            'persons' => $persons,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, items?: array<int, array<string, mixed>>, message?: string}
     */
    public function saveContactQuickView(int $contactId, array $payload): array
    {
        $contact = DirectoryContact::query()->find($contactId);

        if ($contact === null) {
            return ['ok' => false, 'message' => 'Contact not found.'];
        }

        $lastContact = $this->normalizeDate($payload['last_contact'] ?? null);
        $nextContact = $this->normalizeDate($payload['next_contact'] ?? null);
        $type = trim((string) ($payload['type'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $comment = trim((string) ($payload['comment'] ?? ''));
        $commentedAt = now();

        if (strlen($comment) > 5000) {
            return ['ok' => false, 'message' => 'Comment is too long.'];
        }

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Email is invalid.'];
        }

        if ($comment !== '' && $lastContact === null) {
            $lastContact = $commentedAt->toDateString();
        }

        $contact->update([
            'type' => $type !== '' ? $type : null,
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email !== '' ? $email : null,
            'last_contact' => $lastContact,
            'next_contact' => $nextContact,
        ]);

        if ($comment !== '') {
            $contact->comments()->create([
                'user_id' => auth()->id(),
                'commented_at' => $commentedAt,
                'comment' => $comment,
            ]);
        }

        return [
            'ok' => true,
            'items' => $this->getKanbanItems(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLastContactedList(int $limit = 20): array
    {
        return $this->rankedDateList('last_contact', 'desc', $limit);
    }

    /**
     * Upcoming contacts, including overdue (late) dates first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getUpcomingContactList(int $limit = 20): array
    {
        return $this->rankedDateList('next_contact', 'asc', $limit);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapKanbanItem(DirectoryContact $contact): array
    {
        $lastComment = $contact->latestComment?->comment;
        $lastCommentAt = $contact->latestComment?->commented_at?->format('Y-m-d H:i');

        return [
            'id' => $contact->getKey(),
            'title' => $contact->company_name,
            'stage' => (string) ($contact->directory_contact_stage_id ?: DirectoryContactStage::defaultId()),
            'type' => $contact->type ?: '',
            'segment' => $contact->segment ?: '',
            'priority' => $contact->priority?->label() ?: '',
            'country' => $contact->country ?: '—',
            'phone' => $contact->phone ?: '—',
            'last_contact' => $contact->last_contact?->format('Y-m-d') ?: '',
            'next_contact' => $contact->next_contact?->format('Y-m-d') ?: '',
            'persons' => $contact->contactPersons
                ->map(fn ($person): string => $person->fullName())
                ->filter()
                ->implode(', '),
            'comments_count' => (int) ($contact->comments_count ?? 0),
            'last_comment' => $lastComment,
            'last_comment_at' => $lastCommentAt ?? '',
            'last_comment_preview' => filled($lastComment) ? Str::limit($lastComment, 80, '...') : '',
            'edit_url' => DirectoryContactResource::getUrl('edit', ['record' => $contact]),
        ];
    }

    protected function resolveStageId(string $stageKey): ?int
    {
        if ($stageKey === 'unassigned' || $stageKey === '') {
            return DirectoryContactStage::defaultId();
        }

        $stageId = (int) $stageKey;

        if ($stageId <= 0) {
            return null;
        }

        if (! DirectoryContactStage::query()->whereKey($stageId)->exists()) {
            return null;
        }

        return $stageId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function rankedDateList(string $dateKey, string $direction, int $limit): array
    {
        $ranked = collect($this->getKanbanItems())
            ->filter(fn (array $item): bool => filled($item[$dateKey] ?? null))
            ->when(
                $direction === 'desc',
                fn ($items) => $items->sortByDesc($dateKey),
                fn ($items) => $items->sortBy($dateKey),
            )
            ->values();

        if ($limit > 0) {
            $ranked = $ranked->take($limit);
        }

        return $ranked->all();
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->toDateString();
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
