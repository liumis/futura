<?php

namespace App\Filament\Admin\Support;

use App\Models\DocumentConfirmation;
use App\Services\DocumentConfirmationService;
use App\Support\UploadLimits;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentConfirmationActions
{
    public static function requiresAttachment(DocumentConfirmation $confirmation): bool
    {
        $confirmation->loadMissing('document.documentType');

        return (bool) ($confirmation->document?->documentType?->confirmation_attachment_required ?? false);
    }

    /**
     * @return list<Component>
     */
    public static function confirmFormComponents(DocumentConfirmation $confirmation): array
    {
        $components = [
            Forms\Components\Placeholder::make('confirm_notice')
                ->label('')
                ->content('By confirming, you acknowledge that you have viewed and read this document.'),
        ];

        if (self::requiresAttachment($confirmation)) {
            $components[] = UploadLimits::apply(
                Forms\Components\FileUpload::make('attachment')
                    ->label('Upload copy or photo')
                    ->disk('public')
                    ->directory('document-confirmations/'.$confirmation->getKey())
                    ->visibility('public')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'image/gif',
                    ])
                    ->maxFiles(1)
                    ->required()
                    ->helperText('Required for this document type (e.g. medical check certificate). PDF or image.'),
            );
        }

        return $components;
    }

    public static function configureConfirmAction(Action $action): Action
    {
        return $action
            ->modalHeading('Confirm you have read this document')
            ->modalSubmitActionLabel('I have read it')
            ->form(fn (DocumentConfirmation $record): array => self::confirmFormComponents($record))
            ->action(function (DocumentConfirmation $record, array $data): void {
                try {
                    self::submitConfirmation($record, $data);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot confirm')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                        ->danger()
                        ->send();

                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Cannot confirm')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function submitConfirmation(DocumentConfirmation $record, array $data = []): void
    {
        $attachmentPath = self::normalizeUploadedPath($data['attachment'] ?? null);
        $originalName = $attachmentPath !== null ? basename($attachmentPath) : null;

        app(DocumentConfirmationService::class)->confirm(
            $record,
            auth()->user(),
            request()->ip(),
            $attachmentPath,
            $originalName,
        );

        Notification::make()
            ->title('Document confirmed')
            ->success()
            ->send();
    }

    /**
     * Configure confirm action on a document edit page (resolves pending confirmation from document).
     */
    public static function configureConfirmReadActionForDocument(Action $action): Action
    {
        return $action
            ->modalHeading('Confirm you have read this document')
            ->modalSubmitActionLabel('I have read it')
            ->form(function () use ($action): array {
                $livewire = $action->getLivewire();
                $document = $livewire->record ?? null;
                $user = auth()->user();

                if ($document === null || $user === null) {
                    return [];
                }

                $pending = app(DocumentConfirmationService::class)->pendingForUser($user, $document);

                return $pending === null
                    ? []
                    : self::confirmFormComponents($pending);
            })
            ->action(function (array $data) use ($action): void {
                $livewire = $action->getLivewire();
                $document = $livewire->record ?? null;
                $user = auth()->user();

                if ($document === null || $user === null) {
                    return;
                }

                $pending = app(DocumentConfirmationService::class)->pendingForUser($user, $document);

                if ($pending === null) {
                    return;
                }

                try {
                    self::submitConfirmation($pending, $data);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot confirm')
                        ->body(collect($exception->errors())->flatten()->first() ?? 'Validation failed.')
                        ->danger()
                        ->send();

                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Cannot confirm')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function attachmentUrl(DocumentConfirmation $confirmation): ?string
    {
        $path = $confirmation->attachment_path;

        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    protected static function normalizeUploadedPath(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        $path = trim((string) $value);

        return $path === '' ? null : $path;
    }
}
