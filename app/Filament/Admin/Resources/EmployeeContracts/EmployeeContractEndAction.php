<?php

namespace App\Filament\Admin\Resources\EmployeeContracts;

use App\Enums\EmployeeStatus;
use App\Filament\Admin\Support\DokobitSigningUi;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Services\EmployeeContractSigner;
use App\Services\EmployeeContractTerminator;
use App\Support\UploadLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class EmployeeContractEndAction
{
    public static function make(string $name = 'endContract'): Action
    {
        return Action::make($name)
            ->label('End contract')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->modalHeading('End employment contract')
            ->modalDescription('Enter the termination date and reason. Generate a document for Dokobit signing, or upload an already-signed copy. The employee stays active until the termination document is signed.')
            ->modalSubmitActionLabel('Continue')
            ->modalWidth(Width::Large)
            ->visible(fn (Model $record): bool => static::resolveContract($record)?->canEnd() === true)
            ->fillForm(function (Model $record): array {
                $contract = static::resolveContract($record);
                $select = $contract instanceof EmployeeContract
                    ? EmployeeContractSigner::signerSelectOptions($contract)
                    : ['defaults' => []];

                return [
                    'termination_date' => $contract?->termination_date?->toDateString() ?? now()->toDateString(),
                    'termination_reason' => $contract?->termination_reason,
                    'signer_keys' => $select['defaults'],
                ];
            })
            ->form(function (Model $record): array {
                $contract = static::resolveContract($record);
                $select = $contract instanceof EmployeeContract
                    ? EmployeeContractSigner::signerSelectOptions($contract)
                    : ['options' => [], 'defaults' => []];

                return [
                    DatePicker::make('termination_date')
                        ->label('Termination date')
                        ->required()
                        ->native(false),
                    Textarea::make('termination_reason')
                        ->label('Reason')
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                    FileUpload::make('signed_document')
                        ->label('Signed document')
                        ->helperText(UploadLimits::withExistingNote(
                            'Optional. Upload one or more already-signed termination files to skip Dokobit. PDF, Word (.doc, .docx), JPEG, or PNG.',
                        ))
                        ->acceptedFileTypes([
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            '.doc',
                            '.docx',
                        ])
                        ->disk('public')
                        ->directory('employee-contracts')
                        ->visibility('public')
                        ->multiple()
                        ->reorderable()
                        ->maxSize(UploadLimits::MAX_KILOBYTES)
                        ->live()
                        ->columnSpanFull(),
                    Select::make('signer_keys')
                        ->label('Persons for signing')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options($select['options'])
                        ->required(fn (Get $get): bool => EmployeeContractSigner::normalizeUploadPaths($get('signed_document')) === [])
                        ->visible(fn (Get $get): bool => EmployeeContractSigner::normalizeUploadPaths($get('signed_document')) === [])
                        ->helperText('The employee is included by default. Add company representatives as needed.'),
                ];
            })
            ->action(function (Model $record, array $data, Component $livewire) {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return null;
                }

                $contract = static::resolveContract($record);
                if (! $contract instanceof EmployeeContract) {
                    Notification::make()
                        ->title('No contract to end')
                        ->body('This employee has no signed contract that can be ended.')
                        ->warning()
                        ->send();

                    return null;
                }

                $uploadPaths = EmployeeContractSigner::normalizeUploadPaths($data['signed_document'] ?? null);
                $date = (string) ($data['termination_date'] ?? '');
                $reason = (string) ($data['termination_reason'] ?? '');

                if ($uploadPaths !== []) {
                    try {
                        EmployeeContractTerminator::attachSignedUploads(
                            $contract,
                            $date,
                            $reason,
                            $uploadPaths,
                            $user,
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Could not end contract')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return null;
                    }

                    $employee = $contract->fresh('employee')?->employee;
                    $inactive = ($employee?->status ?? null) === EmployeeStatus::Inactive;

                    Notification::make()
                        ->title('Contract ended')
                        ->body($inactive
                            ? 'Signed termination document saved. The employee is now inactive.'
                            : 'Signed termination document saved. This contract is inactive.')
                        ->success()
                        ->send();

                    return null;
                }

                $contract->loadMissing([
                    'terminationDocument.documentSigning.signers',
                    'terminationDocument.contractSigning.signers',
                ]);

                if ($contract->terminationDocument?->awaitsDokobitSignature()) {
                    DokobitSigningUi::openForDocument($livewire, $contract->terminationDocument, $user);

                    return null;
                }

                try {
                    $signing = EmployeeContractTerminator::startSigning(
                        $contract,
                        $date,
                        $reason,
                        $data['signer_keys'] ?? [],
                        $user,
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not start termination signing')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                $document = $signing->document?->fresh([
                    'documentSigning.signers',
                    'contractSigning.signers',
                ]);

                Notification::make()
                    ->title('Termination document ready')
                    ->body('Complete signatures in the Dokobit window. The employee stays active until the document is signed.')
                    ->success()
                    ->send();

                if ($document !== null) {
                    DokobitSigningUi::openForDocument($livewire, $document, $user);
                }

                return null;
            });
    }

    public static function resolveContract(Model $record): ?EmployeeContract
    {
        if ($record instanceof EmployeeContract) {
            return $record;
        }

        if ($record instanceof Employee) {
            return $record->endableContract();
        }

        return null;
    }
}
