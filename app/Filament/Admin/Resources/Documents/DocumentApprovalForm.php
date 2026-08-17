<?php

namespace App\Filament\Admin\Resources\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentDokobitSigner;
use App\Services\DocumentWorkflow;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use RuntimeException;

class DocumentApprovalForm
{
    public const APPROVER_IDS = 'approver_user_ids';

    public const SIGNER_IDS = 'signer_user_ids';

    public const EXTERNAL_INVITEES = 'external_invitees';

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function formComponents(?Document $record = null): array
    {
        $select = DocumentDokobitSigner::signerSelectOptions(
            auth()->user() instanceof User ? auth()->user() : null,
        );

        return [
            Section::make('Approval')
                ->description('Select approvers and/or Dokobit signers. Notifications and invite emails are sent only after you save with a file attached.')
                ->schema([
                    Fieldset::make('Internal approval')
                        ->schema([
                            Select::make(self::APPROVER_IDS)
                                ->label('Approvers')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options($select['options'])
                                ->default([])
                                ->dehydrated()
                                ->helperText('Optional. Selected users must confirm inside this system.')
                                ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),
                        ]),

                    Fieldset::make('Dokobit signing')
                        ->schema([
                            Select::make(self::SIGNER_IDS)
                                ->label('Internal signers')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->options($select['options'])
                                ->default([])
                                ->dehydrated()
                                ->helperText('Sandbox rejects real Mobile-ID data — use Dokobit test identities.')
                                ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),

                            Repeater::make(self::EXTERNAL_INVITEES)
                                ->label('External invitees')
                                ->helperText('People outside this system. They sign directly on Dokobit via email link.')
                                ->schema([
                                    TextInput::make('name')
                                        ->label('First name')
                                        ->required()
                                        ->maxLength(100),
                                    TextInput::make('surname')
                                        ->label('Surname')
                                        ->required()
                                        ->maxLength(100),
                                    TextInput::make('email')
                                        ->label('Email')
                                        ->email()
                                        ->required()
                                        ->maxLength(255),
                                ])
                                ->columns(3)
                                ->defaultItems(0)
                                ->default([])
                                ->dehydrated()
                                ->addActionLabel('Add external invitee')
                                ->collapsible()
                                ->itemLabel(fn (array $state): ?string => filled($state['email'] ?? null)
                                    ? trim(($state['name'] ?? '').' '.($state['surname'] ?? '')).' <'.$state['email'].'>'
                                    : null)
                                ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),
                        ]),
                ])
                ->visible(fn (?Document $record): bool => $record === null || $record->canStartApprovalOrSigningWorkflow())
                ->columnSpanFull(),
        ];
    }

    /**
     * Modal form schema (without the outer Section wrapper).
     *
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function modalFormComponents(): array
    {
        $select = DocumentDokobitSigner::signerSelectOptions(
            auth()->user() instanceof User ? auth()->user() : null,
        );

        return [
            Fieldset::make('Internal approval')
                ->schema([
                    Select::make(self::APPROVER_IDS)
                        ->label('Approvers')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options($select['options'])
                        ->helperText('Optional. Selected users must confirm inside this system.'),
                ]),

            Fieldset::make('Dokobit signing')
                ->schema([
                    Select::make(self::SIGNER_IDS)
                        ->label('Internal signers')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options($select['options'])
                        ->helperText('Sandbox rejects real Mobile-ID data — use Dokobit test identities.'),

                    Repeater::make(self::EXTERNAL_INVITEES)
                        ->label('External invitees')
                        ->helperText('People outside this system. They sign directly on Dokobit via email link.')
                        ->schema([
                            TextInput::make('name')
                                ->label('First name')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('surname')
                                ->label('Surname')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('email')
                                ->label('Email')
                                ->email()
                                ->required()
                                ->maxLength(255),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->addActionLabel('Add external invitee')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => filled($state['email'] ?? null)
                            ? trim(($state['name'] ?? '').' '.($state['surname'] ?? '')).' <'.$state['email'].'>'
                            : null),
                ]),
        ];
    }

    /**
     * @return array{
     *     approver_user_ids: list<int>,
     *     signer_user_ids: list<int>,
     *     external_invitees: list<array{name?: string, surname?: string, email?: string}>
     * }
     */
    public static function extractFromFormData(array &$data): array
    {
        $workflow = [
            self::APPROVER_IDS => array_values(array_filter(
                array_map('intval', (array) ($data[self::APPROVER_IDS] ?? [])),
                fn (int $id): bool => $id > 0,
            )),
            self::SIGNER_IDS => array_values(array_filter(
                array_map('intval', (array) ($data[self::SIGNER_IDS] ?? [])),
                fn (int $id): bool => $id > 0,
            )),
            self::EXTERNAL_INVITEES => array_values(array_filter(
                (array) ($data[self::EXTERNAL_INVITEES] ?? []),
                fn ($row): bool => is_array($row) && filled($row['email'] ?? null),
            )),
        ];

        unset($data[self::APPROVER_IDS], $data[self::SIGNER_IDS], $data[self::EXTERNAL_INVITEES]);

        return $workflow;
    }

    /**
     * @param  array{
     *     approver_user_ids?: list<int>,
     *     signer_user_ids?: list<int>,
     *     external_invitees?: list<array{name?: string, surname?: string, email?: string}>
     * }  $workflow
     */
    public static function hasSelection(array $workflow): bool
    {
        return ($workflow[self::APPROVER_IDS] ?? []) !== []
            || ($workflow[self::SIGNER_IDS] ?? []) !== []
            || ($workflow[self::EXTERNAL_INVITEES] ?? []) !== [];
    }

    public static function hasSigningSelection(array $workflow): bool
    {
        return ($workflow[self::SIGNER_IDS] ?? []) !== []
            || ($workflow[self::EXTERNAL_INVITEES] ?? []) !== [];
    }

    /**
     * @param  list<string>  $localPaths
     */
    public static function assertCanStartWithFiles(
        array $workflow,
        array $localPaths,
        ?Document $record,
        bool $forImmediateApprove = false,
    ): void {
        $hasFile = $localPaths !== [] || ($record?->hasStoredFile() ?? false);

        if ($forImmediateApprove) {
            if (self::hasSelection($workflow)) {
                throw new RuntimeException(
                    'Remove selected approvers/signers before using Save and approve, or use Save to start the approval workflow.',
                );
            }

            if (! $hasFile) {
                throw new RuntimeException('Attach a file before approving the document.');
            }

            return;
        }

        if (! self::hasSelection($workflow)) {
            return;
        }

        if (! $hasFile) {
            throw new RuntimeException('Attach a file before assigning approvers or signers.');
        }

        if (self::hasSigningSelection($workflow)) {
            $paths = $localPaths;
            if ($paths === [] && $record !== null) {
                $name = \App\Services\DocumentBinaryStore::downloadFileName($record);
                if (! str_ends_with(strtolower($name), '.pdf')) {
                    throw new RuntimeException('Dokobit signing requires a PDF file.');
                }
            } else {
                foreach ($paths as $path) {
                    if (! str_ends_with(strtolower((string) $path), '.pdf')) {
                        throw new RuntimeException('Dokobit signing requires a PDF file.');
                    }
                }
            }
        }
    }

    /**
     * @param  array{
     *     approver_user_ids: list<int>,
     *     signer_user_ids: list<int>,
     *     external_invitees: list<array{name?: string, surname?: string, email?: string}>
     * }  $workflow
     * @return array{document: Document, signing: \App\Models\DocumentSigning|null}|null
     */
    public static function startAfterSave(
        Document $document,
        array $workflow,
        ?Component $livewire = null,
    ): ?array {
        if (! self::hasSelection($workflow)) {
            return null;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return null;
        }

        try {
            $result = DocumentWorkflow::start(
                $document->fresh(['documentType']),
                $user,
                $workflow[self::APPROVER_IDS] ?? [],
                $workflow[self::SIGNER_IDS] ?? [],
                $workflow[self::EXTERNAL_INVITEES] ?? [],
            );
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not start approval workflow')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $parts = [];
        if ($result['document']->hasDocumentApprovers()) {
            $parts[] = 'Internal approvals requested';
        }
        if ($result['signing'] !== null) {
            $externalCount = $result['signing']->signers->where('is_external', true)->count();
            $parts[] = 'Dokobit signing started'
                .($externalCount > 0 ? " ({$externalCount} external invite email(s) sent)" : '');
        }

        Notification::make()
            ->title('Workflow started')
            ->body(implode('. ', $parts).'.')
            ->success()
            ->send();

        if (
            $livewire !== null
            && $result['signing'] !== null
            && $result['document']->awaitsDokobitSignature()
            && $result['signing']->signerForUser($user)
            && method_exists($livewire, 'openDokobitSigningFrame')
        ) {
            \App\Filament\Admin\Support\DokobitSigningUi::openForDocument(
                $livewire,
                $result['document'],
                $user,
            );
        }

        return $result;
    }

    public static function validateWorkflowSelection(Get $get, ?Document $record, \Closure $fail): void
    {
        $workflow = [
            self::APPROVER_IDS => (array) ($get(self::APPROVER_IDS) ?? []),
            self::SIGNER_IDS => (array) ($get(self::SIGNER_IDS) ?? []),
            self::EXTERNAL_INVITEES => (array) ($get(self::EXTERNAL_INVITEES) ?? []),
        ];

        if (! self::hasSelection($workflow)) {
            return;
        }

        $files = $get('file_path');
        $uploadCount = 0;
        if (is_array($files)) {
            $uploadCount = count(array_filter($files, fn ($path): bool => filled($path)));
        } elseif (filled($files)) {
            $uploadCount = 1;
        }

        $hasFile = $uploadCount > 0 || ($record?->hasStoredFile() ?? false);

        if (! $hasFile) {
            $fail('Attach a file before assigning approvers or signers.');

            return;
        }

        if (! self::hasSigningSelection($workflow)) {
            return;
        }

        $paths = is_array($files) ? $files : (filled($files) ? [$files] : []);
        if ($paths !== []) {
            foreach ($paths as $path) {
                if (filled($path) && ! str_ends_with(strtolower((string) $path), '.pdf')) {
                    $fail('Dokobit signing requires a PDF file.');

                    return;
                }
            }
        } elseif ($record !== null && $record->hasStoredFile()) {
            $name = \App\Services\DocumentBinaryStore::downloadFileName($record);
            if (! str_ends_with(strtolower($name), '.pdf')) {
                $fail('Dokobit signing requires a PDF file.');
            }
        }
    }

    public static function statusPanel(): \Filament\Forms\Components\Placeholder
    {
        return \Filament\Forms\Components\Placeholder::make('approval_info')
            ->label('Approval')
            ->content(function (?Document $record): HtmlString {
                return new HtmlString(
                    view('filament.admin.components.document-approval-panel', [
                        'record' => $record,
                    ])->render()
                );
            })
            ->visible(fn (?Document $record): bool => $record !== null && ! $record->canStartApprovalOrSigningWorkflow())
            ->columnSpanFull();
    }
}
