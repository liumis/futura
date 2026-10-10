<?php

namespace App\Filament\Admin\Concerns;

use App\Models\Document;
use App\Services\BrandedDocumentPdfGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;
use Tiptap\Editor;

trait CreatesBrandedDocumentPdf
{
    /**
     * @var list<string>
     */
    public array $generatedPdfPaths = [];

    public ?string $generatedPdfPreviewUrl = null;

    public function attachGeneratedBrandedPdf(array $data): void
    {
        $formState = method_exists($this->form, 'getRawState')
            ? $this->form->getRawState()
            : (is_array($this->data ?? null) ? $this->data : []);

        $name = trim((string) ($formState['name'] ?? ''));
        $documentDate = $formState['document_date'] ?? null;
        $body = $this->richEditorHtml($data['body'] ?? null);

        if ($name === '') {
            Notification::make()
                ->title('Document name is required')
                ->body('Fill Document name on the form before creating a PDF.')
                ->warning()
                ->send();

            return;
        }

        if (blank($documentDate)) {
            Notification::make()
                ->title('Document date is required')
                ->body('Fill Document date on the form before creating a PDF.')
                ->warning()
                ->send();

            return;
        }

        if (trim(strip_tags($body)) === '') {
            Notification::make()
                ->title('Text is required')
                ->body('Write the document text in the editor.')
                ->warning()
                ->send();

            return;
        }

        $binary = BrandedDocumentPdfGenerator::generate($name, $documentDate, $body);
        $fileName = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '-', $name) ?? $name;
        $fileName = trim((string) $fileName, ' .');
        $fileName = $fileName !== '' ? $fileName : 'document';
        if (! str_ends_with(mb_strtolower($fileName), '.pdf')) {
            $fileName .= '.pdf';
        }

        $relativePath = 'documents/tmp/'.Str::uuid()->toString().'-'.$fileName;
        Storage::disk('public')->put($relativePath, $binary);

        $this->generatedPdfPaths[] = $relativePath;
        $this->generatedPdfPreviewUrl = Storage::disk('public')->url($relativePath);

        $existing = $this->normalizeUploadedFilePaths($formState['file_path'] ?? []);
        $existing[] = $relativePath;

        $this->form->fill([
            ...$formState,
            'file_path' => array_values(array_unique($existing)),
        ]);

        Notification::make()
            ->title('PDF created')
            ->body('The branded PDF is attached. Save the document to upload it.')
            ->success()
            ->actions([
                Action::make('previewPdf')
                    ->label('Preview')
                    ->url($this->generatedPdfPreviewUrl, shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->send();
    }

    public static function createPdfFormAction(?Document $record = null): Action
    {
        return Action::make('createPdf')
            ->label('Create PDF')
            ->icon('heroicon-o-document-plus')
            ->color('gray')
            ->button()
            ->modalHeading('Create PDF')
            ->modalDescription('Write the document text. The PDF uses company branding. Name and date are taken from the create form.')
            ->modalSubmitActionLabel('Create PDF')
            ->modalWidth('3xl')
            ->visible(fn (): bool => $record === null || $record->canAttachFiles())
            ->form([
                RichEditor::make('body')
                    ->label('Text')
                    ->required()
                    ->fileAttachments(false)
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline'],
                        ['h2', 'h3'],
                        ['alignStart', 'alignCenter', 'alignEnd'],
                        ['bulletList', 'orderedList'],
                        ['undo', 'redo'],
                    ])
                    ->extraInputAttributes([
                        'class' => 'ss-create-pdf-editor',
                        'style' => 'min-height: 28rem;',
                    ])
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Component $livewire): void {
                if (method_exists($livewire, 'attachGeneratedBrandedPdf')) {
                    $livewire->attachGeneratedBrandedPdf($data);
                }

                if (
                    filled($livewire->generatedPdfPreviewUrl ?? null)
                    && method_exists($livewire, 'mountAction')
                ) {
                    $livewire->mountAction('previewGeneratedPdf');
                }
            });
    }

    public function previewGeneratedPdfAction(): Action
    {
        return Action::make('previewGeneratedPdf')
            ->label('Preview PDF')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => filled($this->generatedPdfPreviewUrl))
            ->modalHeading('PDF preview')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('5xl')
            ->modalContent(fn (): HtmlString => new HtmlString(
                view('filament.admin.components.generated-pdf-preview', [
                    'url' => $this->generatedPdfPreviewUrl,
                ])->render()
            ));
    }

    private function richEditorHtml(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_array($body)) {
            try {
                return (string) (new Editor)->setContent($body)->getHTML();
            } catch (\Throwable) {
                return '';
            }
        }

        return '';
    }
}
