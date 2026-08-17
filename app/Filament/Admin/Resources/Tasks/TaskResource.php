<?php

namespace App\Filament\Admin\Resources\Tasks;

use App\Enums\TodoPriority;
use App\Enums\TodoStatus;
use App\Filament\Admin\Resources\Tasks\Pages\CreateTask;
use App\Filament\Admin\Resources\Tasks\Pages\EditTask;
use App\Filament\Admin\Resources\Tasks\Pages\ListTasks;
use App\Filament\Admin\Resources\Tasks\Pages\TaskComments;
use App\Models\Project;
use App\Models\Todo;
use App\Models\TodoComment;
use App\Models\User;
use App\Services\TodoStatusWorkflow;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use App\Livewire\ImageDrawAnnotator;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire as SchemaLivewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set as SchemaSet;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use UnitEnum;

class TaskResource extends Resource
{
    protected static ?string $model = Todo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'List';

    protected static ?string $modelLabel = 'Task';

    protected static ?string $pluralModelLabel = 'Tasks';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 1;

    protected static function commentsModalAction(): Action
    {
        return Action::make('comments_modal')
            ->label('Add comment')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->modalHeading(fn (Todo $record): string => 'Comments: '.$record->displayTitle())
            ->modalSubmitActionLabel('Add comment')
            ->modalWidth('5xl')
            ->fillForm(function (Todo $record): array {
                $comments = $record->comments()
                    ->with('user:id,name,email')
                    ->latest()
                    ->get()
                    ->map(function (TodoComment $comment): array {
                        $isOwner = (int) $comment->user_id === (int) auth()->id();

                        return [
                            'id' => $comment->getKey(),
                            'author_name' => (string) ($comment->user?->name ?? $comment->user?->email ?? 'Unknown user'),
                            'created_at_label' => (string) ($comment->created_at?->format('Y-m-d H:i:s') ?? ''),
                            'content' => (string) $comment->content,
                            'attachments' => $comment->attachments ?? [],
                            'is_owner' => $isOwner,
                            'delete' => false,
                        ];
                    })
                    ->all();

                return [
                    'comments_history' => $comments,
                    'new_content' => '',
                    'new_attachments' => [],
                ];
            })
            ->form([
                Repeater::make('comments_history')
                    ->label('Existing comments')
                    ->addable(false)
                    ->reorderable(false)
                    ->deletable(false)
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('author_name'),
                        Hidden::make('created_at_label'),
                        Hidden::make('is_owner'),
                        Textarea::make('content')
                            ->label(fn (callable $get): string => 'Comment by '.$get('author_name').' at '.$get('created_at_label'))
                            ->rows(3)
                            ->disabled(fn (callable $get): bool => ! ((bool) $get('is_owner'))),
                        Forms\Components\FileUpload::make('attachments')
                            ->label('Files')
                            ->multiple()
                            ->directory('todo-comments')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::note())
                            ->downloadable()
                            ->openable()
                            ->disabled(fn (callable $get): bool => ! ((bool) $get('is_owner'))),
                        Toggle::make('delete')
                            ->label(new HtmlString('<span style="color:#dc2626;text-decoration:underline;cursor:pointer;">Delete comment</span>'))
                            ->visible(fn (callable $get): bool => (bool) $get('is_owner')),
                    ])
                    ->columnSpanFull(),
                Textarea::make('new_content')
                    ->label('New comment')
                    ->rows(4)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('new_attachments')
                    ->label('Files')
                    ->multiple()
                    ->directory('todo-comments')
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->helperText(UploadLimits::note())
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),
            ])
            ->action(function (Todo $record, array $data): void {
                $rows = collect($data['comments_history'] ?? [])
                    ->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));

                $record->comments()->get()->each(function (TodoComment $comment) use ($rows): void {
                    if ((int) $comment->user_id !== (int) auth()->id()) {
                        return;
                    }

                    $row = $rows->get((string) $comment->getKey());
                    if (! is_array($row)) {
                        return;
                    }

                    if ((bool) ($row['delete'] ?? false)) {
                        $comment->delete();

                        return;
                    }

                    $comment->update([
                        'content' => (string) ($row['content'] ?? ''),
                        'attachments' => $row['attachments'] ?? [],
                    ]);
                });

                $newContent = trim((string) ($data['new_content'] ?? ''));
                $newAttachments = array_values(array_filter((array) ($data['new_attachments'] ?? [])));

                if ($newContent === '' && $newAttachments === []) {
                    Notification::make()
                        ->title('Comments updated')
                        ->success()
                        ->send();

                    return;
                }

                TodoComment::query()->create([
                    'todo_id' => $record->getKey(),
                    'user_id' => auth()->id(),
                    'content' => $newContent,
                    'attachments' => $newAttachments,
                ]);

                Notification::make()
                    ->title('Comment added')
                    ->success()
                    ->send();
            });
    }

    protected static function viewTaskAction(): Action
    {
        return Action::make('view_task')
            ->label('View')
            ->icon('heroicon-o-eye')
            ->modalHeading(fn (Todo $record): string => 'Task: '.$record->displayTitle())
            ->modalSubmitActionLabel('Save changes')
            ->modalWidth('4xl')
            ->fillForm(fn (Todo $record): array => [
                'can_edit' => (int) ($record->user_id ?? 0) === (int) (auth()->id() ?? 0),
                'title' => (string) $record->title,
                'project_id' => $record->project_id,
                'user_name' => (string) ($record->user?->name ?? $record->user?->email ?? '—'),
                'status' => (string) ($record->status?->value ?? '—'),
                'priority' => (string) ($record->priority?->value ?? 'regular'),
                'archived' => (bool) $record->archived,
                'start_date' => $record->start_date?->format('Y-m-d H:i:s'),
                'deadline' => $record->deadline?->format('Y-m-d H:i:s'),
                'description' => (string) ($record->description ?? ''),
            ])
            ->form([
                Hidden::make('can_edit'),
                Forms\Components\TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit')))
                    ->columnSpanFull(),
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->options(function (): array {
                        return Project::query()
                            ->where('archived', false)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Project $project): array => [
                                $project->getKey() => $project->label(),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->required()
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit')))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('user_name')
                    ->label('Author')
                    ->disabled(),
                ...self::statusStepperFields(
                    canEditUsing: fn (callable $get): bool => (bool) $get('can_edit'),
                    forceVisible: true,
                ),
                Forms\Components\Select::make('priority')
                    ->label('Priority')
                    ->options(TodoPriority::class)
                    ->native(false)
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                Forms\Components\Checkbox::make('archived')
                    ->label('Archived')
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                Forms\Components\DateTimePicker::make('start_date')
                    ->label('Start date')
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                Forms\Components\DateTimePicker::make('deadline')
                    ->label('Deadline')
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                Textarea::make('description')
                    ->label('Description')
                    ->rows(5)
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit')))
                    ->columnSpanFull(),
            ])
            ->action(function (Todo $record, array $data): void {
                if ((int) ($record->user_id ?? 0) !== (int) (auth()->id() ?? 0)) {
                    Notification::make()
                        ->title('Only task author can update this item')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $record->update([
                        'title' => (string) ($data['title'] ?? $record->title),
                        'project_id' => $data['project_id'] ?? $record->project_id,
                        'status' => $data['status'] ?? ($record->status?->value ?? null),
                        'priority' => $data['priority'] ?? ($record->priority?->value ?? 'regular'),
                        'archived' => (bool) ($data['archived'] ?? false),
                        'start_date' => $data['start_date'] ?? $record->start_date,
                        'deadline' => $data['deadline'] ?? $record->deadline,
                        'description' => (string) ($data['description'] ?? $record->description ?? ''),
                    ]);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not update task')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Task updated')
                    ->success()
                    ->send();
            });
    }

    protected static function editTaskModalAction(): EditAction
    {
        return EditAction::make()
            ->modalHeading(fn (Todo $record): string => $record->displayTitle())
            ->modalWidth('7xl')
            ->mutateRecordDataUsing(function (array $data, Todo $record): array {
                $record->loadMissing(['watchers', 'comments.user']);

                $data['watchers'] = $record->watchers->pluck('id')->all();
                $data['comments_history'] = $record->comments
                    ->sortByDesc('created_at')
                    ->map(fn (TodoComment $comment): array => self::mapCommentHistoryRow($comment))
                    ->values()
                    ->all();
                $data['new_comment_content'] = '';
                $data['new_comment_attachments'] = [];

                return $data;
            })
            ->mutateFormDataUsing(function (array $data, Todo $record): array {
                self::syncTaskCommentsFromFormData($record, $data);

                unset(
                    $data['comments_history'],
                    $data['new_comment_content'],
                    $data['new_comment_attachments'],
                );

                return $data;
            });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->relationship(
                        name: 'project',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('archived', false),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Project $record): string => $record->label())
                    ->searchable(['name', 'code'])
                    ->preload()
                    ->required(),

                Forms\Components\DateTimePicker::make('deadline')
                    ->required()
                    ->default(fn (): string => now()->addDay()->setTime(18, 0)->format('Y-m-d H:i:s'))
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $set('start_date', Carbon::parse($state)->subHour()->format('Y-m-d H:i:s'));
                    }),

                Forms\Components\DateTimePicker::make('start_date')
                    ->label('Start date')
                    ->default(fn (): string => now()->addDay()->setTime(17, 0)->format('Y-m-d H:i:s')),

                Forms\Components\Toggle::make('all_day')
                    ->label('All-day')
                    ->helperText('Synced to Outlook as an all-day event when calendar sync is enabled.'),

                Forms\Components\Toggle::make('calendar_sync_enabled')
                    ->label('Sync to Outlook Calendar')
                    ->helperText('Requires a connected Outlook calendar under System → Outlook Calendar. Scheduling changes sync both ways; deleting the Outlook event never deletes this task.'),

                Forms\Components\Textarea::make('description')
                    ->rows(5)
                    ->columnSpanFull(),

                Forms\Components\Checkbox::make('has_finances')
                    ->label('Finances')
                    ->live(),

                Section::make('Finances')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('total_income')
                                    ->label('Total income')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01),

                                Forms\Components\TextInput::make('income_left')
                                    ->label('Income left')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01),

                                Forms\Components\TextInput::make('total_payment')
                                    ->label('Total expences')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01),

                                Forms\Components\TextInput::make('payment_left')
                                    ->label('Expences left')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01),
                            ]),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (callable $get): bool => (bool) $get('has_finances')),

                Forms\Components\Checkbox::make('is_recurring')
                    ->label('Recurring')
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                Section::make('Recurring')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('recurrence_interval')
                                    ->label('Repeat every')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(99)
                                    ->default(1)
                                    ->required()
                                    ->dehydrated(false),

                                Forms\Components\Select::make('recurrence_unit')
                                    ->label('Unit')
                                    ->options([
                                        'day' => 'day',
                                        'week' => 'week',
                                        'month' => 'month',
                                        'year' => 'year',
                                    ])
                                    ->default('week')
                                    ->native(false)
                                    ->required()
                                    ->live()
                                    ->dehydrated(false),
                            ]),

                        Forms\Components\CheckboxList::make('recurrence_weekdays')
                            ->label('Repeat on')
                            ->options([
                                1 => 'M',
                                2 => 'T',
                                3 => 'W',
                                4 => 'T',
                                5 => 'F',
                                6 => 'S',
                                7 => 'S',
                            ])
                            ->columns(7)
                            ->default(fn (callable $get): array => [
                                Carbon::parse($get('start_date') ?: now())->dayOfWeekIso,
                            ])
                            ->visible(fn (callable $get): bool => ($get('recurrence_unit') ?? 'week') === 'week')
                            ->dehydrated(false),

                        Forms\Components\Radio::make('recurrence_ends')
                            ->label('Ends')
                            ->options([
                                'on' => 'On',
                                'after' => 'After',
                            ])
                            ->default('after')
                            ->live()
                            ->required()
                            ->dehydrated(false),

                        Forms\Components\DatePicker::make('recurrence_ends_on')
                            ->label('End date')
                            ->native(false)
                            ->required(fn (callable $get): bool => ($get('recurrence_ends') ?? 'after') === 'on')
                            ->visible(fn (callable $get): bool => ($get('recurrence_ends') ?? 'after') === 'on')
                            ->default(fn (): string => now()->addMonths(3)->toDateString())
                            ->dehydrated(false),

                        Forms\Components\TextInput::make('recurrence_occurrences')
                            ->label('Occurrences')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(13)
                            ->required(fn (callable $get): bool => ($get('recurrence_ends') ?? 'after') === 'after')
                            ->visible(fn (callable $get): bool => ($get('recurrence_ends') ?? 'after') === 'after')
                            ->dehydrated(false)
                            ->suffix('occurrences'),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (callable $get, string $operation): bool => $operation === 'create' && (bool) $get('is_recurring')),

                Forms\Components\Select::make('watchers')
                    ->relationship('watchers', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->fullName() !== '' ? $record->fullName() : (string) ($record->email ?? ''))
                    ->multiple()
                    ->searchable(['name', 'surname', 'email'])
                    ->preload(),

                Forms\Components\Placeholder::make('existing_task_files')
                    ->label('Attached files')
                    ->content(function (?Todo $record): HtmlString {
                        if ($record === null) {
                            return new HtmlString('');
                        }

                        $links = $record->sharepointAttachmentLinks();
                        if ($links === []) {
                            return new HtmlString('<span class="text-sm text-gray-500">No files on SharePoint yet.</span>');
                        }

                        $items = [];
                        foreach ($links as $file) {
                            $name = e($file['name']);
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url
                                ? '<li><a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                                : '<li>'.$name.'</li>';
                        }

                        return new HtmlString(
                            '<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>'
                        );
                    })
                    ->visible(fn (?Todo $record): bool => $record !== null)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('attachments')
                    ->label(fn (?Todo $record): string => ($record?->hasSharepointFiles() ?? false)
                        ? 'Add more files'
                        : 'Files')
                    ->disk('public')
                    ->directory('todos/tmp')
                    ->visibility('public')
                    ->multiple()
                    ->live()
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->helperText(UploadLimits::withExistingNote(
                        'Stored on SharePoint under tasks/{id}-{name}. Temporary local upload is removed after save. JPG/PNG: use Annotate photos below, then save.',
                    ))
                    ->downloadable(false)
                    ->openable(false)
                    ->columnSpanFull(),

                Section::make('Annotate photos')
                    ->description('Click Draw on a JPG/JPEG/PNG. SharePoint photos update immediately; new uploads need a task Save after drawing.')
                    ->schema([
                        SchemaLivewire::make(ImageDrawAnnotator::class, function (?Todo $record, Get $get): array {
                            return [
                                'images' => self::drawableTaskImages($record, $get('attachments') ?? []),
                                'disk' => 'public',
                            ];
                        })
                            ->key(function (?Todo $record, Get $get): string {
                                return 'annotate-task-images-'.($record?->getKey() ?? 'new').'-'.sha1(json_encode([
                                    $record?->sharepoint_files,
                                    $get('attachments'),
                                ]));
                            }),
                    ])
                    ->visible(function (?Todo $record, Get $get): bool {
                        return self::drawableTaskImages($record, $get('attachments') ?? []) !== [];
                    })
                    ->columnSpanFull(),

                Forms\Components\Select::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->fullName() !== '' ? $record->fullName() : (string) ($record->email ?? ''))
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->required()
                    ->live()
                    ->default(fn (): ?int => auth()->id())
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $set('responsible_id', $state);
                    }),

                Forms\Components\Select::make('responsible_id')
                    ->label('Responsible')
                    ->relationship('responsible', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->fullName() !== '' ? $record->fullName() : (string) ($record->email ?? ''))
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->required()
                    ->live()
                    ->default(fn (): ?int => auth()->id()),

                ...self::statusStepperFields(),

                Forms\Components\Select::make('priority')
                    ->label('Priority')
                    ->options(TodoPriority::class)
                    ->required()
                    ->native(false)
                    ->default(TodoPriority::Regular),

                Forms\Components\Checkbox::make('archived')
                    ->label('Archived'),

                Section::make(function (?Todo $record, Get $get): string {
                    $count = count(array_filter(
                        (array) ($get('comments_history') ?? []),
                        fn ($row): bool => is_array($row) && ! (bool) ($row['delete'] ?? false),
                    ));

                    if ($count === 0 && $record instanceof Todo) {
                        $count = (int) $record->comments()->count();
                    }

                    return 'Comments ('.$count.')';
                })
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        Section::make(function (Get $get, ?Todo $record): string {
                            $count = count(array_filter(
                                (array) ($get('comments_history') ?? []),
                                fn ($row): bool => is_array($row) && ! (bool) ($row['delete'] ?? false),
                            ));

                            if ($count === 0 && $record instanceof Todo) {
                                $count = (int) $record->comments()->count();
                            }

                            return 'Previous comments ('.$count.')';
                        })
                            ->description('Click to expand and review or edit existing comments.')
                            ->icon('heroicon-o-queue-list')
                            ->collapsible()
                            ->collapsed()
                            ->schema([
                                Repeater::make('comments_history')
                                    ->label('')
                                    ->addable(false)
                                    ->reorderable(false)
                                    ->deletable(false)
                                    ->schema([
                                        Hidden::make('id'),
                                        Hidden::make('author_name'),
                                        Hidden::make('created_at_label'),
                                        Hidden::make('is_owner'),
                                        Textarea::make('content')
                                            ->label(fn (callable $get): string => 'Comment by '.$get('author_name').' at '.$get('created_at_label'))
                                            ->rows(3)
                                            ->disabled(fn (callable $get): bool => ! ((bool) $get('is_owner'))),
                                        Forms\Components\FileUpload::make('attachments')
                                            ->label('Files')
                                            ->multiple()
                                            ->directory('todo-comments')
                                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                                            ->helperText(UploadLimits::note())
                                            ->downloadable()
                                            ->openable()
                                            ->disabled(fn (callable $get): bool => ! ((bool) $get('is_owner'))),

                                        Forms\Components\Placeholder::make('annotate_comment_attachments')
                                            ->label('Annotate photos')
                                            ->content(function (Get $get): HtmlString {
                                                /** @var array<int, string> $paths */
                                                $paths = array_values(array_filter((array) ($get('attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                                                $wireKey = 'annotate-comment-attachments-'.sha1(json_encode($paths));

                                                return new HtmlString(
                                                    view('filament.admin.components.image-draw-annotator-actions', [
                                                        'paths' => $paths,
                                                        'wireKey' => $wireKey,
                                                    ])->render(),
                                                );
                                            })
                                            ->visible(fn (callable $get): bool => (bool) $get('is_owner') && ($get('attachments') ?? []) !== [])
                                            ->columnSpanFull(),
                                        Toggle::make('delete')
                                            ->label(new HtmlString('<span style="color:#dc2626;text-decoration:underline;cursor:pointer;">Delete comment</span>'))
                                            ->visible(fn (callable $get): bool => (bool) $get('is_owner')),
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),

                        Section::make('Add a new comment')
                            ->description('Write below, then save the task. This is separate from the history above.')
                            ->icon('heroicon-o-chat-bubble-left-ellipsis')
                            ->extraAttributes([
                                'class' => 'ss-new-comment-form',
                                'style' => 'border:1px solid #cbd5e1;border-left:4px solid #2b3a67;background:linear-gradient(180deg,#f8fafc 0%,#eef2f7 100%);border-radius:0.75rem;',
                            ])
                            ->schema([
                                Textarea::make('new_comment_content')
                                    ->label('Message')
                                    ->placeholder('Type your comment…')
                                    ->rows(4)
                                    ->maxLength(5000)
                                    ->columnSpanFull(),
                                Forms\Components\FileUpload::make('new_comment_attachments')
                                    ->label('Attach files')
                                    ->multiple()
                                    ->directory('todo-comments')
                                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                                    ->helperText(UploadLimits::note())
                                    ->downloadable()
                                    ->openable()
                                    ->live()
                                    ->columnSpanFull(),

                                Forms\Components\Placeholder::make('annotate_new_comment_attachments')
                                    ->label('Annotate photos')
                                    ->content(function (Get $get): HtmlString {
                                        /** @var array<int, string> $paths */
                                        $paths = array_values(array_filter((array) ($get('new_comment_attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                                        $wireKey = 'annotate-new-comment-attachments-'.sha1(json_encode($paths));

                                        return new HtmlString(
                                            view('filament.admin.components.image-draw-annotator-actions', [
                                                'paths' => $paths,
                                                'wireKey' => $wireKey,
                                            ])->render(),
                                        );
                                    })
                                    ->visible(fn (Get $get): bool => ($get('new_comment_attachments') ?? []) !== [])
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->icon('heroicon-o-pencil-square')
                    ->iconColor('gray')
                    ->action(self::editTaskModalAction())
                    ->formatStateUsing(fn (Todo $record): string => $record->displayTitle())
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deadline')
                    ->label('Deadline')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('priority')
                    ->badge()
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('archived')
                    ->label('Archive')
                    ->onColor('warning')
                    ->offColor('gray')
                    ->sortable()
                    ->afterStateUpdated(function (Todo $record, mixed $state): void {
                        Notification::make()
                            ->title($state ? 'Task archived' : 'Task restored')
                            ->success()
                            ->send();
                    }),

                Tables\Columns\TextColumn::make('finances')
                    ->label('Finances')
                    ->state(function (Todo $record): ?string {
                        $parts = [];

                        if ($record->total_income !== null || $record->income_left !== null) {
                            $income = [];
                            if ($record->total_income !== null) {
                                $income[] = 'Total: '.(string) $record->total_income;
                            }
                            if ($record->income_left !== null) {
                                $income[] = 'Left: '.(string) $record->income_left;
                            }
                            if ($income !== []) {
                                $parts[] = 'Income: '.implode(' ', $income);
                            }
                        }

                        if ($record->total_payment !== null || $record->payment_left !== null) {
                            $expences = [];
                            if ($record->total_payment !== null) {
                                $expences[] = 'Total: '.(string) $record->total_payment;
                            }
                            if ($record->payment_left !== null) {
                                $expences[] = 'Left: '.(string) $record->payment_left;
                            }
                            if ($expences !== []) {
                                $parts[] = 'Expences: '.implode(' ', $expences);
                            }
                        }

                        return $parts === [] ? null : implode("\n", $parts);
                    })
                    ->wrap()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('comments_count')
                    ->label('Comments')
                    ->counts('comments')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('gray')
                    ->action(self::commentsModalAction())
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestComment.content')
                    ->label('Last comment')
                    ->icon('heroicon-o-eye')
                    ->iconColor('gray')
                    ->state(fn (Todo $record): ?string => $record->latestComment?->content)
                    ->formatStateUsing(fn (?string $state): string => Str::limit((string) ($state ?? ''), 300, '...'))
                    ->tooltip(function (Todo $record): ?string {
                        $comment = $record->latestComment;
                        if ($comment === null) {
                            return null;
                        }

                        $author = (string) ($comment->user?->name ?? $comment->user?->email ?? 'Unknown user');
                        $date = (string) ($comment->created_at?->format('Y-m-d H:i:s') ?? '');
                        $content = (string) ($comment->content ?? '');

                        return trim($author.' | '.$date."\n".'Comment:'."\n".$content);
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('archived')
                    ->label('Archived')
                    ->options([
                        'active' => 'Active only',
                        'archived' => 'Archived only',
                        'all' => 'All tasks',
                    ])
                    ->default('active')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = filled($data['value'] ?? null) ? (string) $data['value'] : 'active';

                        if ($value === 'active') {
                            $query->where('archived', false);
                        } elseif ($value === 'archived') {
                            $query->where('archived', true);
                        }
                    }),

                SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options(TodoStatus::class)
                    ->native(false),

                SelectFilter::make('priority')
                    ->label('Priority')
                    ->multiple()
                    ->options(TodoPriority::class)
                    ->native(false),

                Filter::make('financials_only')
                    ->label('Financials only')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNotNull('total_income')
                            ->orWhereNotNull('income_left')
                            ->orWhereNotNull('total_payment')
                            ->orWhereNotNull('payment_left');
                    })),

                Filter::make('income')
                    ->label('Income')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNotNull('total_income')
                            ->orWhereNotNull('income_left');
                    })),

                Filter::make('payments')
                    ->label('Expences')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $q): void {
                        $q->whereNotNull('total_payment')
                            ->orWhereNotNull('payment_left');
                    })),

                SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('deadline_range')
                    ->label('Deadline')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('From'),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (filled($from)) {
                            $query->whereDate('deadline', '>=', $from);
                        }

                        if (filled($until)) {
                            $query->whereDate('deadline', '<=', $until);
                        }
                    }),
            ])
            ->recordActions([
                self::editTaskModalAction(),
                self::commentsModalAction(),
                self::viewTaskAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'project', 'latestComment.user']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * Status is always New on create (hidden). On edit, back/forward step buttons.
     *
     * @param  (callable(callable): bool)|null  $canEditUsing
     * @return array<int, mixed>
     */
    public static function statusStepperFields(
        ?callable $canEditUsing = null,
        bool $visibleOnCreate = false,
        bool $forceVisible = false,
    ): array {
        $canEdit = $canEditUsing ?? fn (mixed $_get = null): bool => true;
        $stepperVisible = fn (string $operation): bool => $forceVisible
            || $visibleOnCreate
            || $operation === 'edit';

        return [
            Hidden::make('status')
                ->default(TodoStatus::New)
                ->dehydrated()
                ->live(),

            Forms\Components\Placeholder::make('status_current')
                ->label('Status')
                ->content(function (Get $get): HtmlString {
                    $status = TodoStatusWorkflow::normalize($get('status')) ?? TodoStatus::New;
                    $label = e((string) ($status->getLabel() ?? $status->value));
                    $class = match ($status) {
                        TodoStatus::New => 'background:#6b7280;color:#fff;',
                        TodoStatus::InProgress => 'background:#f59e0b;color:#111;',
                        TodoStatus::Confirm => 'background:#2563eb;color:#fff;',
                        TodoStatus::Returned => 'background:#9333ea;color:#fff;',
                        TodoStatus::Done => 'background:#16a34a;color:#fff;',
                    };

                    return new HtmlString(
                        '<span style="display:inline-flex;align-items:center;border-radius:9999px;padding:0.35rem 0.85rem;font-size:0.875rem;font-weight:600;'.$class.'">'.$label.'</span>'
                    );
                })
                ->visible($stepperVisible),

            Actions::make([
                Action::make('stepStatusBack')
                    ->label(function (Get $get, ?Todo $record) use ($canEdit): string {
                        if (! $canEdit($get)) {
                            return '← Back';
                        }

                        $back = TodoStatusWorkflow::stepBack(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        return $back
                            ? '← '.$back->getLabel()
                            : '← Back';
                    })
                    ->color('gray')
                    ->disabled(function (Get $get, ?Todo $record) use ($canEdit): bool {
                        if (! $canEdit($get)) {
                            return true;
                        }

                        return TodoStatusWorkflow::stepBack(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        ) === null;
                    })
                    ->modalHeading(function (Get $get, ?Todo $record): string {
                        $back = TodoStatusWorkflow::stepBack(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        return $back
                            ? 'Move status to '.$back->getLabel().'?'
                            : 'Change status?';
                    })
                    ->modalSubmitActionLabel('Confirm')
                    ->modalWidth('lg')
                    ->form(self::statusStepConfirmFormSchema())
                    ->action(function (array $data, SchemaSet $set, Get $get, ?Todo $record) use ($canEdit): void {
                        if (! $canEdit($get)) {
                            return;
                        }

                        $back = TodoStatusWorkflow::stepBack(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        if ($back === null) {
                            return;
                        }

                        self::applyStatusStep($set, $get, $record, $back, $data);
                    }),
                Action::make('stepStatusForward')
                    ->label(function (Get $get, ?Todo $record) use ($canEdit): string {
                        if (! $canEdit($get)) {
                            return 'Forward →';
                        }

                        $forward = TodoStatusWorkflow::stepForward(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        return $forward
                            ? $forward->getLabel().' →'
                            : 'Forward →';
                    })
                    ->color('primary')
                    ->disabled(function (Get $get, ?Todo $record) use ($canEdit): bool {
                        if (! $canEdit($get)) {
                            return true;
                        }

                        return TodoStatusWorkflow::stepForward(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        ) === null;
                    })
                    ->modalHeading(function (Get $get, ?Todo $record): string {
                        $forward = TodoStatusWorkflow::stepForward(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        return $forward
                            ? 'Move status to '.$forward->getLabel().'?'
                            : 'Change status?';
                    })
                    ->modalSubmitActionLabel('Confirm')
                    ->modalWidth('lg')
                    ->form(self::statusStepConfirmFormSchema())
                    ->action(function (array $data, SchemaSet $set, Get $get, ?Todo $record) use ($canEdit): void {
                        if (! $canEdit($get)) {
                            return;
                        }

                        $forward = TodoStatusWorkflow::stepForward(
                            TodoStatusWorkflow::proxyFromForm($get, $record),
                            $get('status'),
                            auth()->user(),
                        );

                        if ($forward === null) {
                            return;
                        }

                        self::applyStatusStep($set, $get, $record, $forward, $data);
                    }),
            ])
                ->visible($stepperVisible)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function statusStepConfirmFormSchema(): array
    {
        return [
            Textarea::make('comment')
                ->label('Comment')
                ->helperText('Optional — saved to this task’s comments.')
                ->rows(4)
                ->maxLength(5000)
                ->columnSpanFull(),
            Forms\Components\FileUpload::make('attachments')
                ->label('Files')
                ->multiple()
                ->directory('todo-comments')
                ->maxSize(UploadLimits::MAX_KILOBYTES)
                ->helperText(UploadLimits::note())
                ->downloadable()
                ->openable()
                ->columnSpanFull(),

            Forms\Components\Placeholder::make('annotate_status_step_attachments')
                ->label('Annotate photos')
                ->content(function (Get $get): HtmlString {
                    $paths = array_values(array_filter((array) ($get('attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                    $wireKey = 'annotate-status-step-attachments-'.sha1(json_encode($paths));

                    return new HtmlString(
                        view('filament.admin.components.image-draw-annotator-actions', [
                            'paths' => $paths,
                            'wireKey' => $wireKey,
                        ])->render(),
                    );
                })
                ->visible(fn (Get $get): bool => ($get('attachments') ?? []) !== [])
                ->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function applyStatusStep(
        SchemaSet $set,
        Get $get,
        ?Todo $record,
        TodoStatus $nextStatus,
        array $data,
    ): void {
        $comment = trim((string) ($data['comment'] ?? ''));
        $attachments = array_values(array_filter((array) ($data['attachments'] ?? [])));

        if ($record instanceof Todo) {
            try {
                $record->update(['status' => $nextStatus->value]);
            } catch (\Throwable $exception) {
                Notification::make()
                    ->title('Could not change status')
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();

                return;
            }

            if ($comment !== '' || $attachments !== []) {
                TodoComment::query()->create([
                    'todo_id' => $record->getKey(),
                    'user_id' => auth()->id(),
                    'content' => $comment,
                    'attachments' => $attachments,
                ]);
            }

            $set('status', $nextStatus->value);
            $set('comments_history', self::commentHistoryRows($record->fresh(['comments.user']) ?? $record));
            $set('new_comment_content', '');
            $set('new_comment_attachments', []);

            Notification::make()
                ->title('Status updated to '.$nextStatus->getLabel())
                ->success()
                ->send();

            return;
        }

        $set('status', $nextStatus->value);

        if ($comment !== '' || $attachments !== []) {
            $history = collect($get('comments_history') ?? [])->values()->all();
            array_unshift($history, [
                'id' => null,
                'author_name' => (string) (auth()->user()?->name ?? auth()->user()?->email ?? 'You'),
                'created_at_label' => now()->format('Y-m-d H:i:s'),
                'content' => $comment,
                'attachments' => $attachments,
                'is_owner' => true,
                'delete' => false,
            ]);
            $set('comments_history', $history);
            $set('new_comment_content', $comment !== '' ? $comment : ($get('new_comment_content') ?? ''));
            if ($attachments !== []) {
                $set('new_comment_attachments', $attachments);
            }
        }
    }

    /**
     * @param  mixed  $attachments
     * @return list<array<string, mixed>>
     */
    public static function drawableTaskImages(?Todo $record, mixed $attachments = []): array
    {
        $images = [];

        if ($record instanceof Todo) {
            foreach ($record->sharepointAttachmentLinks() as $file) {
                $name = (string) ($file['name'] ?? '');
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    continue;
                }
                if (blank($file['item_id'] ?? null)) {
                    continue;
                }

                $images[] = [
                    'kind' => 'sharepoint',
                    'key' => 'sp:'.$file['item_id'],
                    'label' => $name,
                    'name' => $name,
                    'item_id' => (string) $file['item_id'],
                    'todo_id' => (int) $record->getKey(),
                    'path' => $file['path'] ?? null,
                    'view_url' => filled($file['url'] ?? null) ? (string) $file['url'] : null,
                    'uploaded_at' => filled($file['uploaded_at'] ?? null) ? (string) $file['uploaded_at'] : null,
                ];
            }
        }

        foreach (array_values(array_filter((array) $attachments, fn ($p): bool => filled($p) && is_string($p))) as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }

            $uploadedAt = null;
            try {
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                    $uploadedAt = \Illuminate\Support\Carbon::createFromTimestamp(
                        \Illuminate\Support\Facades\Storage::disk('public')->lastModified($path)
                    )->toIso8601String();
                }
            } catch (\Throwable) {
                $uploadedAt = null;
            }

            $images[] = [
                'kind' => 'local',
                'key' => 'local:'.$path,
                'label' => 'New upload: '.basename(str_replace('\\', '/', $path)),
                'path' => $path,
                'view_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'annotate.preview',
                    now()->addMinutes(60),
                    ['path' => $path],
                ),
                'uploaded_at' => $uploadedAt,
            ];
        }

        return $images;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function commentHistoryRows(?Todo $record): array
    {
        if (! $record instanceof Todo) {
            return [];
        }

        $record->loadMissing(['comments.user']);

        return $record->comments
            ->sortByDesc('created_at')
            ->values()
            ->map(fn (TodoComment $comment): array => self::mapCommentHistoryRow($comment))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapCommentHistoryRow(TodoComment $comment): array
    {
        return [
            'id' => $comment->getKey(),
            'author_name' => (string) ($comment->user?->name ?? $comment->user?->email ?? 'Unknown user'),
            'created_at_label' => (string) ($comment->created_at?->format('Y-m-d H:i:s') ?? ''),
            'content' => (string) $comment->content,
            'attachments' => array_values(array_filter((array) ($comment->attachments ?? []))),
            'is_owner' => (int) ($comment->user_id ?? 0) === (int) (auth()->id() ?? 0),
            'delete' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function syncTaskCommentsFromFormData(Todo $record, array $data): void
    {
        $rows = collect($data['comments_history'] ?? [])
            ->filter(fn ($row): bool => is_array($row))
            ->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));

        $record->comments()->get()->each(function (TodoComment $comment) use ($rows): void {
            if ((int) $comment->user_id !== (int) auth()->id()) {
                return;
            }

            $row = $rows->get((string) $comment->getKey());
            if (! is_array($row)) {
                return;
            }

            if ((bool) ($row['delete'] ?? false)) {
                $comment->delete();

                return;
            }

            $comment->update([
                'content' => (string) ($row['content'] ?? ''),
                'attachments' => array_values(array_filter((array) ($row['attachments'] ?? []))),
            ]);
        });

        $newContent = trim((string) ($data['new_comment_content'] ?? ''));
        $newAttachments = array_values(array_filter((array) ($data['new_comment_attachments'] ?? [])));

        if ($newContent === '' && $newAttachments === []) {
            return;
        }

        TodoComment::query()->create([
            'todo_id' => $record->getKey(),
            'user_id' => auth()->id(),
            'content' => $newContent,
            'attachments' => $newAttachments,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'edit' => EditTask::route('/{record}/edit'),
            'comments' => TaskComments::route('/{record}/comments'),
        ];
    }
}
