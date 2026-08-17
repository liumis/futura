<?php

namespace App\Filament\Admin\Resources\EmployeeContracts;

use App\Filament\Admin\Support\DokobitSigningUi;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Services\EmployeeContractSigner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Livewire\Component;

class EmployeeContractSignAction
{
    public static function make(string $name = 'signContract'): Action
    {
        return Action::make($name)
            ->label('Sign')
            ->icon('heroicon-o-pencil-square')
            ->color('primary')
            ->modalHeading('Sign employment contract')
            ->modalDescription('Select persons who must sign this contract in Dokobit. Sandbox rejects real Mobile-ID data — use Dokobit test identities (System → Dokobit).')
            ->modalSubmitActionLabel('Open signing')
            ->modalWidth(Width::Large)
            ->visible(fn (EmployeeContract $record): bool => $record->canStartSigning())
            ->fillForm(function (EmployeeContract $record): array {
                $select = EmployeeContractSigner::signerSelectOptions($record);

                return [
                    'signer_keys' => $select['defaults'],
                ];
            })
            ->form(function (EmployeeContract $record): array {
                $select = EmployeeContractSigner::signerSelectOptions($record);

                return [
                    Select::make('signer_keys')
                        ->label('Persons for signing')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->options($select['options'])
                        ->helperText('The employee is included by default. Add company representatives as needed.'),
                ];
            })
            ->action(function (EmployeeContract $record, array $data, Component $livewire) {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return null;
                }

                // Resume existing pending document signing for this contract.
                $record->loadMissing(['document.documentSigning.signers', 'document.contractSigning.signers', 'latestSigning']);
                if ($record->document?->awaitsDokobitSignature()) {
                    DokobitSigningUi::openForDocument($livewire, $record->document, $user);

                    return null;
                }

                try {
                    $signing = EmployeeContractSigner::start(
                        $record,
                        $data['signer_keys'] ?? [],
                        $user,
                    );
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not start signing')
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
                    ->title('Signing ready')
                    ->body('Contract PDF saved to Documents. Complete signatures in the Dokobit window.')
                    ->success()
                    ->send();

                if ($document !== null) {
                    DokobitSigningUi::openForDocument($livewire, $document, $user);
                }

                return null;
            });
    }
}
