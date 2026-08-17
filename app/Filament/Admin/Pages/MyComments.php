<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\Project;
use App\Models\Todo;
use App\Models\TodoComment;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use UnitEnum;

class MyComments extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'My comments';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.my-comments';

    protected static function quickViewTodoAction(): Action
    {
        return Action::make('quick_view_todo')
            ->label('Quick view')
            ->modalHeading(fn (TodoComment $record): string => 'Task: '.($record->todo?->displayTitle() ?? 'Task'))
            ->modalSubmitActionLabel('Save changes')
            ->modalWidth('4xl')
            ->fillForm(fn (TodoComment $record): array => [
                'can_edit' => (int) ($record->todo?->user_id ?? 0) === (int) (auth()->id() ?? 0),
                'title' => (string) ($record->todo?->title ?? ''),
                'project_id' => $record->todo?->project_id,
                'user_name' => (string) ($record->todo?->user?->name ?? $record->todo?->user?->email ?? '—'),
                'status' => (string) ($record->todo?->status?->value ?? '—'),
                'start_date' => $record->todo?->start_date?->format('Y-m-d H:i:s'),
                'deadline' => $record->todo?->deadline?->format('Y-m-d H:i:s'),
                'description' => (string) ($record->todo?->description ?? ''),
            ])
            ->form([
                Hidden::make('can_edit'),
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit')))
                    ->columnSpanFull(),
                Select::make('project_id')
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
                TextInput::make('user_name')
                    ->label('Author')
                    ->disabled(),
                ...TaskResource::statusStepperFields(
                    canEditUsing: fn (callable $get): bool => (bool) $get('can_edit'),
                    forceVisible: true,
                ),
                DateTimePicker::make('start_date')
                    ->label('Start date')
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                DateTimePicker::make('deadline')
                    ->label('Deadline')
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit'))),
                Textarea::make('description')
                    ->label('Description')
                    ->rows(5)
                    ->disabled(fn (callable $get): bool => ! ((bool) $get('can_edit')))
                    ->columnSpanFull(),
            ])
            ->action(function (TodoComment $record, array $data): void {
                $todo = $record->todo;
                if (! $todo instanceof Todo) {
                    Notification::make()
                        ->title('Task not found')
                        ->danger()
                        ->send();

                    return;
                }

                if ((int) ($todo->user_id ?? 0) !== (int) (auth()->id() ?? 0)) {
                    Notification::make()
                        ->title('Only task author can update this item')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $todo->update([
                        'title' => (string) ($data['title'] ?? $todo->title),
                        'project_id' => $data['project_id'] ?? $todo->project_id,
                        'status' => $data['status'] ?? ($todo->status?->value ?? null),
                        'start_date' => $data['start_date'] ?? $todo->start_date,
                        'deadline' => $data['deadline'] ?? $todo->deadline,
                        'description' => (string) ($data['description'] ?? $todo->description ?? ''),
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

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TodoComment::query()
                    ->with(['todo.project', 'todo.user:id,name,email', 'user:id,name,email'])
                    ->where('user_id', auth()->id())
            )
            ->columns([
                Tables\Columns\TextColumn::make('todo.title')
                    ->label('Task')
                    ->icon('heroicon-o-eye')
                    ->iconColor('gray')
                    ->action(self::quickViewTodoAction())
                    ->formatStateUsing(fn (TodoComment $record): string => $record->todo?->displayTitle() ?? '—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('todo.user.name')
                    ->label('Item author')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('todo.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => (string) ($state?->value ?? $state ?? '—'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Comment date')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->label('Text')
                    ->icon('heroicon-o-eye')
                    ->iconColor('gray')
                    ->formatStateUsing(fn (?string $state): string => Str::limit((string) ($state ?? ''), 300, '...'))
                    ->tooltip(function (TodoComment $record): string {
                        $author = (string) ($record->user?->name ?? $record->user?->email ?? 'Unknown user');
                        $date = (string) ($record->created_at?->format('Y-m-d H:i:s') ?? '');
                        $content = (string) ($record->content ?? '');

                        return trim($author.' | '.$date."\n".'Comment:'."\n".$content);
                    })
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('todo_comments_count')
                    ->label('Comments')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('gray')
                    ->state(fn (TodoComment $record): int => (int) ($record->todo?->comments()->count() ?? 0))
                    ->action(
                        Action::make('todo_comments_modal')
                            ->label('Comments')
                            ->modalHeading(fn (TodoComment $record): string => 'Comments: '.($record->todo?->displayTitle() ?? 'Todo'))
                            ->modalSubmitActionLabel('Add comment')
                            ->modalWidth('5xl')
                            ->fillForm(function (TodoComment $record): array {
                                $todo = $record->todo;
                                if (! $todo instanceof Todo) {
                                    return [
                                        'todo_id' => null,
                                        'comments_history' => [],
                                        'new_content' => '',
                                        'new_attachments' => [],
                                    ];
                                }

                                $comments = $todo->comments()
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
                                    'todo_id' => $todo->getKey(),
                                    'comments_history' => $comments,
                                    'new_content' => '',
                                    'new_attachments' => [],
                                ];
                            })
                            ->form([
                                Hidden::make('todo_id'),
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
                                        FileUpload::make('attachments')
                                            ->label('Files')
                                            ->multiple()
                                            ->directory('todo-comments')
                                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                                            ->helperText(UploadLimits::note())
                                            ->downloadable()
                                            ->openable()
                                            ->disabled(fn (callable $get): bool => ! ((bool) $get('is_owner'))),

                                        Placeholder::make('annotate_comment_history_attachments')
                                            ->label('Annotate photos')
                                            ->content(function (Get $get): HtmlString {
                                                $paths = array_values(array_filter((array) ($get('attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                                                $wireKey = 'annotate-mycomments-history-'.sha1(json_encode($paths));

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
                                Textarea::make('new_content')
                                    ->label('New comment')
                                    ->rows(4)
                                    ->maxLength(5000)
                                    ->columnSpanFull(),
                                FileUpload::make('new_attachments')
                                    ->label('Files')
                                    ->multiple()
                                    ->directory('todo-comments')
                                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                                    ->helperText(UploadLimits::note())
                                    ->downloadable()
                                    ->openable()
                                    ->columnSpanFull(),

                                Placeholder::make('annotate_mycomments_new_attachments')
                                    ->label('Annotate photos')
                                    ->content(function (Get $get): HtmlString {
                                        $paths = array_values(array_filter((array) ($get('new_attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                                        $wireKey = 'annotate-mycomments-new-'.sha1(json_encode($paths));

                                        return new HtmlString(
                                            view('filament.admin.components.image-draw-annotator-actions', [
                                                'paths' => $paths,
                                                'wireKey' => $wireKey,
                                            ])->render(),
                                        );
                                    })
                                    ->visible(fn (Get $get): bool => ($get('new_attachments') ?? []) !== [])
                                    ->columnSpanFull(),
                            ])
                            ->action(function (TodoComment $record, array $data): void {
                                $todoId = (int) ($data['todo_id'] ?? 0);
                                if ($todoId <= 0) {
                                    Notification::make()
                                        ->title('Task not found')
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                $rows = collect($data['comments_history'] ?? [])
                                    ->keyBy(fn (array $row): string => (string) ($row['id'] ?? ''));

                                TodoComment::query()
                                    ->where('todo_id', $todoId)
                                    ->get()
                                    ->each(function (TodoComment $comment) use ($rows): void {
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
                                $newAttachments = $data['new_attachments'] ?? [];

                                if ($newContent !== '' || $newAttachments !== []) {
                                    TodoComment::query()->create([
                                        'todo_id' => $todoId,
                                        'user_id' => auth()->id(),
                                        'content' => $newContent,
                                        'attachments' => $newAttachments,
                                    ]);
                                }

                                Notification::make()
                                    ->title('Comments updated')
                                    ->success()
                                    ->send();
                            })
                    )
                    ->sortable(false),
            ])
            ->recordActions([
                Action::make('edit_comment')
                    ->label('Edit comment')
                    ->form([
                        Textarea::make('content')
                            ->label('Comment')
                            ->rows(4)
                            ->required()
                            ->maxLength(5000),
                    ])
                    ->fillForm(fn (TodoComment $record): array => ['content' => $record->content])
                    ->action(function (TodoComment $record, array $data): void {
                        $record->update([
                            'content' => trim((string) ($data['content'] ?? '')),
                        ]);

                        Notification::make()
                            ->title('Comment updated')
                            ->success()
                            ->send();
                    }),
                Action::make('delete_comment')
                    ->label('Delete comment')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (TodoComment $record): void {
                        $record->delete();

                        Notification::make()
                            ->title('Comment deleted')
                            ->success()
                            ->send();
                    }),
                self::quickViewTodoAction()
                    ->name('view_todo')
                    ->label('View Task'),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('user_id', auth()->id())
            );
    }
}
