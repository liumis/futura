<?php

namespace App\Filament\Admin\Resources\Documents;

use App\Filament\Admin\Resources\Documents\Pages;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentApprover;
use App\Services\DocumentDokobitSigner;
use App\Services\DocumentWorkflow;
use App\Services\EmployeeContractSigner;
use App\Services\EmployeePaymentReportApprover;
use App\Services\DividendPaymentReportApprover;
use App\Support\UploadLimits;
use App\Services\SharepointDocumentUploader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $modelLabel = 'Document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\DateTimePicker::make('document_date')
                    ->label('Document date')
                    ->required()
                    ->default(fn (): \Carbon\CarbonInterface => now())
                    ->native(false)
                    ->live()
                    ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),

                Forms\Components\TextInput::make('name')
                    ->label('Document name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Select::make('document_type_id')
                    ->label('Document type')
                    ->relationship('documentType', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),

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
                    ->native(false)
                    ->disabled(fn (?Document $record): bool => $record?->isLocked() ?? false),

                Forms\Components\Placeholder::make('owner')
                    ->label('Owner')
                    ->content(fn (?Document $record): string => $record?->uploadedBy?->fullName()
                        ?: ($record?->uploadedBy?->email ?: '—'))
                    ->visible(fn (?Document $record): bool => $record !== null),

                Forms\Components\Placeholder::make('existing_files')
                    ->label('Attached files')
                    ->content(function (?Document $record): HtmlString {
                        if ($record === null) {
                            return new HtmlString('');
                        }

                        $status = match (true) {
                            $record->isApproved() => '<p class="mb-2 text-sm font-medium text-success-600 dark:text-success-400">Approved — files are locked.</p>',
                            $record->hasCompletedSignature() => '<p class="mb-2 text-sm font-medium text-success-600 dark:text-success-400">Signed — files are locked.</p>',
                            $record->awaitsDokobitSignature() => '<p class="mb-2 text-sm font-medium text-warning-600 dark:text-warning-400">Signature in progress — files are locked.</p>',
                            default => '',
                        };

                        if (! is_array($record->sharepoint_files) || $record->sharepoint_files === []) {
                            return new HtmlString(
                                $status.'<span class="text-sm text-gray-500">No files attached yet.</span>'
                            );
                        }

                        $items = [];
                        foreach ($record->sharepoint_files as $file) {
                            if (! is_array($file)) {
                                continue;
                            }
                            $name = e((string) ($file['name'] ?? basename((string) ($file['path'] ?? 'file'))));
                            $url = filled($file['web_url'] ?? null) ? e((string) $file['web_url']) : null;
                            $items[] = $url
                                ? '<li><a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                                : '<li>'.$name.'</li>';
                        }

                        return new HtmlString(
                            $status.'<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>'
                        );
                    })
                    ->visible(fn (?Document $record): bool => $record !== null)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('file_path')
                    ->label(fn (?Document $record): string => ($record?->attachedFilesCount() ?? 0) > 0
                        ? 'Add more files'
                        : 'File')
                    ->disk('public')
                    ->directory('documents/tmp')
                    ->visibility('public')
                    ->multiple()
                    ->reorderable()
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->downloadable(false)
                    ->openable(false)
                    ->live()
                    ->helperText(fn (?Document $record): string => UploadLimits::withExistingNote(
                        match (true) {
                            $record === null => 'Optional on save. Required to approve or sign. Files are stored on SharePoint only (temporary local upload is removed after save).',
                            $record->hasStoredFile() => 'Upload additional file(s). Existing SharePoint files are kept. A file is required to approve or sign.',
                            default => 'Optional on save. Required to approve or sign. Files are stored on SharePoint only (temporary local upload is removed after save).',
                        },
                    ))
                    ->visible(fn (?Document $record): bool => $record === null || $record->canAttachFiles())
                    ->dehydrated(fn (?Document $record): bool => $record === null || $record->canAttachFiles())
                    ->rules([
                        fn (Get $get, ?Document $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record): void {
                            DocumentApprovalForm::validateWorkflowSelection($get, $record, $fail);
                        },
                    ])
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('filename')
                    ->label('Filename')
                    ->content(fn (Get $get, ?Document $record): string => static::filenamePreviewFromForm($get, $record))
                    ->columnSpanFull(),

                ...DocumentApprovalForm::formComponents(),

                DocumentApprovalForm::statusPanel(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_date')
                    ->label('Date')
                    ->date('Y-m-d')
                    ->description(fn (Document $record): ?string => $record->document_date?->format('H:i:s'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Document name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Document $record): ?string => $record->trashed() ? 'Deleted' : null)
                    ->color(fn (Document $record): ?string => $record->trashed() ? 'danger' : null),

                Tables\Columns\TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->formatStateUsing(fn (Document $record): string => $record->project?->label() ?: '—')
                    ->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('project', function (Builder $projects) use ($search): void {
                            $projects->where(function (Builder $match) use ($search): void {
                                $match->where('name', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%");
                            });
                        });
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('uploadedBy.name')
                    ->label('Owner')
                    ->formatStateUsing(fn (Document $record): string => $record->uploadedBy?->fullName()
                        ?: ($record->uploadedBy?->email ?: '—'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('uploadedBy', function (Builder $users) use ($search): void {
                            $users->where(function (Builder $match) use ($search): void {
                                $match->where('name', 'like', "%{$search}%")
                                    ->orWhere('surname', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                            });
                        });
                    })
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('attached_files_display')
                    ->label('Files')
                    ->html()
                    ->getStateUsing(function (Document $record): string {
                        return view('filament.admin.tables.columns.document-files-count', [
                            'record' => $record,
                        ])->render();
                    })
                    ->alignCenter()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            '(CASE
                                WHEN sharepoint_files IS NOT NULL AND JSON_LENGTH(sharepoint_files) > 0 THEN JSON_LENGTH(sharepoint_files)
                                WHEN sharepoint_item_id IS NOT NULL AND sharepoint_item_id != \'\' THEN 1
                                WHEN file_path IS NOT NULL AND file_path != \'\' THEN 1
                                ELSE 0
                            END) '.$direction
                        );
                    }),

                Tables\Columns\TextColumn::make('view_file')
                    ->label('View file')
                    ->html()
                    ->getStateUsing(function (Document $record): string {
                        $files = $record->attachedFileLinks();

                        if ($files === []) {
                            return '—';
                        }

                        $links = [];
                        $multiple = count($files) > 1;

                        foreach ($files as $file) {
                            $label = $multiple
                                ? e(\Illuminate\Support\Str::limit((string) $file['name'], 28))
                                : 'View file';

                            if (filled($file['url'] ?? null)) {
                                $links[] = '<a href="'.e((string) $file['url']).'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>';
                            } else {
                                $links[] = '<span class="text-gray-400 dark:text-gray-500" title="Link unavailable">'.$label.'</span>';
                            }
                        }

                        return implode('<br>', $links);
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('approver_full_name')
                    ->label('Approved')
                    ->getStateUsing(function (Document $record): string {
                        $labels = $record->approvalColumnLabels();

                        return $labels !== [] ? implode(', ', $labels) : '—';
                    })
                    ->description(fn (Document $record): ?string => $record->isApproved()
                        ? optional($record->approval_date)->format('Y-m-d H:i:s')
                        : ($record->pendingInternalApproverLabels() !== [] ? 'Pending confirmation' : null))
                    ->icon(fn (Document $record): string => $record->isApproved()
                        ? 'heroicon-o-lock-closed'
                        : 'heroicon-o-lock-open')
                    ->color(fn (Document $record): string => $record->isApproved()
                        ? 'success'
                        : ($record->pendingInternalApproverLabels() !== [] ? 'warning' : 'gray'))
                    ->wrap(),

                Tables\Columns\TextColumn::make('signed_summary')
                    ->label('Signed')
                    ->getStateUsing(function (Document $record): string {
                        $labels = $record->signedColumnLabels();

                        return $labels !== [] ? implode(', ', $labels) : '—';
                    })
                    ->color(fn (Document $record): string => $record->signedColumnLabels() !== []
                        ? (
                            collect($record->signedColumnLabels())->contains(fn (string $label): bool => str_contains($label, '(pending)'))
                                ? 'warning'
                                : 'success'
                        )
                        : 'gray')
                    ->wrap(),
            ])
            ->filters([
                Filter::make('document_date')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return;
                        }

                        $column = $query->getModel()->qualifyColumn('document_date');

                        if (filled($from)) {
                            $query->where($column, '>=', $from.' 00:00:00');
                        }

                        if (filled($until)) {
                            $query->where($column, '<=', $until.' 23:59:59');
                        }
                    }),

                SelectFilter::make('document_type_id')
                    ->label('Document type')
                    ->relationship('documentType', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Project $record): string => $record->label())
                    ->searchable(['name', 'code'])
                    ->preload()
                    ->native(false),

                SelectFilter::make('user_uploaded_id')
                    ->label('Owner')
                    ->relationship('uploadedBy', 'name')
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => filled(trim($record->fullName()))
                        ? $record->fullName()
                        : (string) ($record->email ?? 'User #'.$record->getKey()))
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\TernaryFilter::make('flag_approved')
                    ->label('Approved')
                    ->placeholder('All')
                    ->trueLabel('Approved only')
                    ->falseLabel('Not approved'),

                Tables\Filters\TernaryFilter::make('pending_approval')
                    ->label('Pending approval')
                    ->placeholder('All')
                    ->trueLabel('Pending approval')
                    ->falseLabel('Not pending approval')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->pendingApproval(),
                        false: fn (Builder $query): Builder => $query->notPendingApproval(),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Tables\Filters\TernaryFilter::make('signed')
                    ->label('Signed')
                    ->placeholder('All')
                    ->trueLabel('Signed only')
                    ->falseLabel('Not signed')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->signed(),
                        false: fn (Builder $query): Builder => $query->notSigned(),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Tables\Filters\TernaryFilter::make('pending_signing')
                    ->label('Pending signing')
                    ->placeholder('All')
                    ->trueLabel('Pending signing')
                    ->falseLabel('Not pending signing')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->pendingSigning(),
                        false: fn (Builder $query): Builder => $query->notPendingSigning(),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                Tables\Filters\TernaryFilter::make('has_files')
                    ->label('Files')
                    ->placeholder('All')
                    ->trueLabel('Has files')
                    ->falseLabel('No files')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->withFiles(),
                        false: fn (Builder $query): Builder => $query->withoutFiles(),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                TrashedFilter::make()
                    ->label('Deleted')
                    ->placeholder('Active only')
                    ->trueLabel('Active and deleted')
                    ->falseLabel('Deleted only'),
            ])
            ->defaultSort('document_date', 'desc')
            ->recordActionsColumnLabel('Actions')
            ->recordActionsAlignment('start')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->iconButton()
                    ->tooltip('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve document')
                    ->modalDescription(fn (Document $record): string => $record->currentUserHasPendingApproval()
                        || $record->hasDocumentApprovers()
                        ? 'Confirm your approval for "'.$record->name.'"? Other pending approvals/signatures may still be required.'
                        : 'Approve and lock "'.$record->name.'"? This will store approval metadata and prevent further edits.')
                    ->modalSubmitActionLabel('Approve')
                    ->visible(fn (Document $record): bool => ! $record->trashed())
                    ->disabled(fn (Document $record): bool => ! $record->currentUserCanApprove())
                    ->action(function (Document $record): void {
                        if ($record->paymentReport !== null) {
                            static::confirmPaymentReportDocument($record);
                        } elseif ($record->dividendPaymentReport !== null) {
                            static::confirmDividendPaymentReportDocument($record);
                        } elseif ($record->currentUserHasPendingApproval() || $record->hasDocumentApprovers()) {
                            static::confirmOrJoinDocumentApproval($record);
                        } else {
                            static::approveDocument($record);
                        }
                    }),

                Action::make('sign')
                    ->label('Sign')
                    ->icon('heroicon-o-finger-print')
                    ->iconButton()
                    ->tooltip('Sign')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Sign document')
                    ->modalDescription(fn (Document $record): string => $record->awaitsDokobitSignature()
                        ? 'Open Dokobit to complete your digital signature for "'.$record->name.'".'
                        : 'Start Dokobit digital signing for "'.$record->name.'"? You will be the signer.')
                    ->modalSubmitActionLabel('Sign')
                    ->visible(fn (Document $record): bool => ! $record->trashed())
                    ->disabled(fn (Document $record): bool => ! $record->currentUserCanSign())
                    ->action(function (Document $record, \Livewire\Component $livewire) {
                        return static::signDocument($record, $livewire);
                    }),

                DocumentSignAction::make()
                    ->iconButton()
                    ->tooltip(fn (Document $record): string => $record->canManageApprovalOrSigningWorkflow()
                        ? 'Approvals & Sign'
                        : 'Open Dokobit')
                    ->visible(fn (Document $record): bool => ! $record->trashed() && auth()->user() instanceof User)
                    ->disabled(fn (Document $record): bool => ! DocumentSignAction::canUse($record)),

                DocumentCloneAction::make()
                    ->iconButton()
                    ->tooltip('Clone'),

                EditAction::make()
                    ->iconButton()
                    ->tooltip('Edit')
                    ->visible(fn (Document $record): bool => ! $record->trashed()),

                RestoreAction::make()
                    ->iconButton()
                    ->tooltip('Restore'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ])
            ->checkIfRecordIsSelectableUsing(
                fn (Document $record): bool => ! $record->isLocked() || $record->trashed(),
            );
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'documentType',
            'project',
            'uploadedBy',
            'approvedBy',
            'approvers',
            'paymentReport.pendingApprovers',
            'paymentReport.approvers',
            'dividendPaymentReport.pendingApprovers',
            'dividendPaymentReport.approvers',
            'contractSigning.signers',
            'documentSigning.signers',
        ]);
    }

    public static function filenamePreviewFromForm(Get $get, ?Document $record): string
    {
        $files = $get('file_path');
        $uploadCount = 0;

        if (is_array($files)) {
            $uploadCount = count(array_filter($files, fn ($path): bool => filled($path)));
        } elseif (filled($files)) {
            $uploadCount = 1;
        }

        $fileCount = $uploadCount > 0
            ? $uploadCount
            : max(1, $record?->attachedFilesCount() ?? 1);

        return SharepointDocumentUploader::filenamePreview(
            $record?->getKey(),
            $get('document_date') ?? $record?->document_date,
            $get('name') ?? $record?->name,
            $fileCount,
        );
    }

    public static function approveDocument(Document $record, bool $notify = true): bool
    {
        $user = auth()->user();

        if ($user === null) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return false;
        }

        try {
            DocumentApprover::approve(
                $record,
                $user,
                (string) request()->ip(),
                (string) request()->userAgent(),
            );
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Approval failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        if ($notify) {
            Notification::make()
                ->title('Document approved and locked')
                ->success()
                ->send();
        }

        return true;
    }

    public static function confirmOrJoinDocumentApproval(Document $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return false;
        }

        try {
            DocumentWorkflow::approveAsParticipant($record, $user);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Approval failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Your approval was recorded')
            ->success()
            ->send();

        return true;
    }

    public static function confirmDocumentApproval(Document $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return false;
        }

        try {
            DocumentWorkflow::confirmApproval($record, $user);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Approval failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Your approval was recorded')
            ->success()
            ->send();

        return true;
    }

    public static function confirmPaymentReportDocument(Document $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return false;
        }

        $report = $record->paymentReport;
        if ($report === null) {
            return false;
        }

        try {
            EmployeePaymentReportApprover::confirmBy($report, $user);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Approval failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Document approved')
            ->success()
            ->send();

        return true;
    }

    public static function confirmDividendPaymentReportDocument(Document $record): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            Notification::make()
                ->title('Not authenticated')
                ->danger()
                ->send();

            return false;
        }

        $report = $record->dividendPaymentReport;
        if ($report === null) {
            return false;
        }

        try {
            DividendPaymentReportApprover::confirmBy($report, $user);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Approval failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Document approved')
            ->success()
            ->send();

        return true;
    }

    public static function signDocument(Document $record, ?\Livewire\Component $livewire = null): mixed
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        if ($record->awaitsDokobitSignature()) {
            return static::resumeDokobitSigning($record, $user, $livewire);
        }

        try {
            $signing = DocumentDokobitSigner::start($record, [$user->getKey()], $user);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Could not start Dokobit signing')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $document = $signing->document?->fresh(['documentSigning.signers'])
            ?? $record->fresh(['documentSigning.signers']);

        Notification::make()
            ->title('Dokobit signing ready')
            ->success()
            ->send();

        if ($livewire !== null && $document !== null && method_exists($livewire, 'openDokobitSigningFrame')) {
            \App\Filament\Admin\Support\DokobitSigningUi::openForDocument($livewire, $document, $user);

            return null;
        }

        $url = DocumentDokobitSigner::openSigningUrl($document, $user);

        return filled($url) ? redirect()->away($url) : null;
    }

    public static function resumeDokobitSigning(Document $record, User $user, ?\Livewire\Component $livewire = null): mixed
    {
        if ($livewire !== null && method_exists($livewire, 'openDokobitSigningFrame')) {
            \App\Filament\Admin\Support\DokobitSigningUi::openForDocument(
                $livewire,
                $record->fresh(['documentSigning.signers', 'contractSigning.signers']),
                $user,
            );

            return null;
        }

        if ($record->documentSigning?->isPending()) {
            try {
                DocumentDokobitSigner::syncStatus($record->documentSigning);
                $record->refresh();
            } catch (\Throwable $exception) {
                Notification::make()
                    ->title('Could not refresh Dokobit status')
                    ->body($exception->getMessage())
                    ->warning()
                    ->send();
            }

            if ($record->isApproved()) {
                Notification::make()
                    ->title('Document signing completed')
                    ->success()
                    ->send();

                return null;
            }

            $url = DocumentDokobitSigner::openSigningUrl($record, $user);
            if (filled($url)) {
                return redirect()->away($url);
            }
        }

        if ($record->contractSigning?->isPending()) {
            try {
                EmployeeContractSigner::syncStatus($record->contractSigning);
                $record->refresh();
            } catch (\Throwable $exception) {
                Notification::make()
                    ->title('Could not refresh Dokobit status')
                    ->body($exception->getMessage())
                    ->warning()
                    ->send();
            }

            if ($record->isApproved()) {
                Notification::make()
                    ->title('Contract signing completed')
                    ->success()
                    ->send();

                return null;
            }

            $url = EmployeeContractSigner::openSigningUrl($record, $user);
            if (filled($url)) {
                return redirect()->away($url);
            }
        }

        Notification::make()
            ->title('Signing URL unavailable')
            ->body('No pending Dokobit signing link was found for this document.')
            ->warning()
            ->send();

        return null;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canRestore(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }
}
