<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Services\DocumentIntegrityChecker;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class VerifyDocument extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Verify document';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Verify document';

    protected static ?string $slug = 'verify-document';

    protected string $view = 'filament.admin.pages.verify-document';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array{
     *     hash: string,
     *     valid: bool,
     *     matches: list<array{document_id: int, name: string, document_date: ?string, type: string, approved_by: string, approval_date: ?string, matched_as: string, edit_url: string}>
     * }|null
     */
    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill([
            'file' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file')
                    ->label('Document file')
                    ->helperText(UploadLimits::withExistingNote(
                        'Upload a file to compare its SHA256 hash with stored content_hash and pdf_hash values from approved documents.',
                    ))
                    ->disk('local')
                    ->directory('document-verify-temp')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label('Verify')
                ->icon('heroicon-o-shield-check')
                ->color('primary')
                ->action('verify'),
        ];
    }

    public function verify(): void
    {
        $state = $this->form->getState();
        $path = $state['file'] ?? null;

        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        if (! is_string($path) || $path === '') {
            Notification::make()
                ->title('Upload a file first')
                ->warning()
                ->send();

            return;
        }

        try {
            $check = DocumentIntegrityChecker::verifyFromDisk('local', $path);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Verification failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            if (is_string($path) && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }

        $this->result = [
            'hash' => $check['hash'],
            'valid' => $check['valid'],
            'matches' => $check['matches']->map(function (array $match): array {
                $document = $match['document'];
                $approver = $document->approvedBy;
                $approverName = $approver?->fullName() ?: '—';

                return [
                    'document_id' => $document->id,
                    'name' => (string) $document->name,
                    'document_date' => optional($document->document_date)->format('Y-m-d H:i:s'),
                    'type' => $document->documentType?->name ?? '—',
                    'approved_by' => $approverName,
                    'approval_date' => optional($document->approval_date)->format('Y-m-d H:i:s'),
                    'matched_as' => $match['matched_as'],
                    'edit_url' => DocumentResource::getUrl('edit', ['record' => $document]),
                ];
            })->values()->all(),
        ];

        $this->form->fill(['file' => null]);

        if ($check['valid']) {
            Notification::make()
                ->title('Document is valid')
                ->body('SHA256 matches a stored approved document hash.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Document is not valid')
                ->body('No matching content_hash or pdf_hash was found.')
                ->danger()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
