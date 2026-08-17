<?php

namespace App\Filament\Admin\Resources\Tasks\Pages;

use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Models\TodoComment;
use App\Support\UploadLimits;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class TaskComments extends Page implements HasForms
{
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.tasks.pages.task-comments';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->loadMissing('project');

        $this->form->fill([
            'content' => '',
            'attachments' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Textarea::make('content')
                    ->label('Comment')
                    ->rows(4)
                    ->required()
                    ->maxLength(5000)
                    ->columnSpanFull(),

                FileUpload::make('attachments')
                    ->label('Files')
                    ->multiple()
                    ->directory('todo-comments')
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->helperText(UploadLimits::note())
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),

                Placeholder::make('annotate_comment_attachments')
                    ->label('Annotate photos')
                    ->content(function (Get $get): HtmlString {
                        $paths = array_values(array_filter((array) ($get('attachments') ?? []), fn ($p): bool => filled($p) && is_string($p)));
                        $wireKey = 'annotate-task-comments-'.sha1(json_encode($paths));

                        return new HtmlString(
                            view('filament.admin.components.image-draw-annotator-actions', [
                                'paths' => $paths,
                                'wireKey' => $wireKey,
                            ])->render(),
                        );
                    })
                    ->visible(fn (Get $get): bool => ($get('attachments') ?? []) !== [])
                    ->columnSpanFull(),
            ]);
    }

    public function createComment(): void
    {
        $state = $this->form->getState();

        TodoComment::query()->create([
            'todo_id' => $this->record->getKey(),
            'user_id' => auth()->id(),
            'content' => (string) ($state['content'] ?? ''),
            'attachments' => $state['attachments'] ?? [],
        ]);

        Notification::make()
            ->title('Comment added')
            ->success()
            ->send();

        $this->form->fill([
            'content' => '',
            'attachments' => [],
        ]);
    }

    /**
     * @return Collection<int, TodoComment>
     */
    public function getComments(): Collection
    {
        return $this->record
            ->comments()
            ->with('user:id,name,email')
            ->latest()
            ->get();
    }

    public function getTitle(): string
    {
        return 'Comments: '.$this->record->displayTitle();
    }
}
