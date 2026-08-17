<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasDokobitSigningModal;
use App\Filament\Admin\Resources\Documents\DocumentCloneAction;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Documents\DocumentSignAction;
use App\Models\Document;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class IncompleteDocuments extends Page implements HasTable
{
    use HasDokobitSigningModal;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Incomplete documents';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Incomplete documents';

    protected static ?string $slug = 'incomplete-documents';

    protected string $view = 'filament.admin.pages.incomplete-documents';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Document::query()->incomplete()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Document::query()
                    ->incomplete()
                    ->with([
                        'documentType',
                        'uploadedBy',
                        'approvedBy',
                        'approvers',
                        'paymentReport.pendingApprovers',
                        'dividendPaymentReport.pendingApprovers',
                        'contractSigning.signers',
                        'documentSigning.signers',
                    ])
            )
            ->columns([
                Tables\Columns\TextColumn::make('document_date')
                    ->label('Date')
                    ->date('Y-m-d')
                    ->description(fn (Document $record): ?string => $record->document_date?->format('H:i:s'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Document name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('documentType.name')
                    ->label('Document type')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Document $record): string => $record->pendingApproverLabels() !== [] ? 'warning' : 'gray')
                    ->getStateUsing(fn (Document $record): string => $record->statusLabel()),

                Tables\Columns\TextColumn::make('missing_approval')
                    ->label('Awaiting approval from')
                    ->getStateUsing(fn (Document $record): string => $record->missingApprovalSummary())
                    ->description(function (Document $record): ?string {
                        if ($record->paymentReport?->status) {
                            return $record->paymentReport->status->label();
                        }

                        if ($record->dividendPaymentReport?->status) {
                            return $record->dividendPaymentReport->status->label();
                        }

                        if ($record->contractSigning?->status) {
                            return $record->contractSigning->status->label();
                        }

                        if ($record->documentSigning?->status) {
                            return $record->documentSigning->status->label();
                        }

                        return null;
                    })
                    ->badge(fn (Document $record): bool => $record->pendingApproverLabels() !== [])
                    ->color('warning')
                    ->wrap(),

                Tables\Columns\TextColumn::make('uploadedBy.name')
                    ->label('Uploaded by')
                    ->formatStateUsing(fn (Document $record): string => $record->uploadedBy?->fullName() ?: '—')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('file_path')
                    ->label('File')
                    ->formatStateUsing(function (Document $record): string {
                        if (filled($record->sharepoint_path)) {
                            return basename(str_replace('\\', '/', (string) $record->sharepoint_path));
                        }

                        return filled($record->file_path)
                            ? basename((string) $record->file_path)
                            : '—';
                    })
                    ->url(fn (Document $record): ?string => $record->displayFileUrl())
                    ->openUrlInNewTab()
                    ->placeholder('—'),
            ])
            ->defaultSort('document_date', 'desc')
            ->emptyStateHeading('No incomplete documents')
            ->emptyStateDescription('Documents waiting for approval or signature will appear here.')
            ->recordActions([
                Action::make('openSharepoint')
                    ->label('SharePoint')
                    ->icon('heroicon-o-cloud')
                    ->iconButton()
                    ->color('info')
                    ->tooltip('Open in SharePoint')
                    ->url(fn (Document $record): ?string => $record->sharepoint_web_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Document $record): bool => $record->hasSharepointLink()),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve document')
                    ->modalDescription(fn (Document $record): string => $record->currentUserHasPendingApproval()
                        || $record->hasDocumentApprovers()
                        ? 'Confirm your approval for "'.$record->name.'"? Other pending approvals/signatures may still be required.'
                        : 'Approve and lock "'.$record->name.'"?')
                    ->modalSubmitActionLabel('Approve')
                    ->visible(fn (Document $record): bool => $record->currentUserCanApprove())
                    ->action(function (Document $record): void {
                        $ok = false;

                        if ($record->paymentReport !== null) {
                            $ok = DocumentResource::confirmPaymentReportDocument($record);
                        } elseif ($record->dividendPaymentReport !== null) {
                            $ok = DocumentResource::confirmDividendPaymentReportDocument($record);
                        } elseif ($record->currentUserHasPendingApproval() || $record->hasDocumentApprovers()) {
                            $ok = DocumentResource::confirmOrJoinDocumentApproval($record);
                        } else {
                            $ok = DocumentResource::approveDocument($record);
                        }

                        if ($ok) {
                            $this->resetTable();
                        }
                    }),

                Action::make('sign')
                    ->label('Sign')
                    ->icon('heroicon-o-finger-print')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Sign document')
                    ->modalDescription(fn (Document $record): string => $record->awaitsDokobitSignature()
                        ? 'Open Dokobit to complete your digital signature for "'.$record->name.'".'
                        : 'Start Dokobit digital signing for "'.$record->name.'"? You will be the signer.')
                    ->modalSubmitActionLabel('Sign')
                    ->visible(fn (Document $record): bool => $record->currentUserCanSign())
                    ->action(function (Document $record) {
                        return DocumentResource::signDocument($record, $this);
                    }),

                DocumentSignAction::make(),

                DocumentCloneAction::make(),

                EditAction::make()
                    ->url(fn (Document $record): string => DocumentResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
