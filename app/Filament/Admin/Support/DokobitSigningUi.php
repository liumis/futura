<?php

namespace App\Filament\Admin\Support;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentDokobitSigner;
use App\Services\EmployeeContractSigner;
use Filament\Notifications\Notification;
use Livewire\Component;

class DokobitSigningUi
{
    public static function openForDocument(Component $livewire, Document $document, ?User $user = null): bool
    {
        $user ??= auth()->user() instanceof User ? auth()->user() : null;

        $document->loadMissing(['documentSigning.signers', 'contractSigning.signers']);

        $url = null;

        if ($document->documentSigning?->isPending()) {
            $url = DocumentDokobitSigner::openSigningUrl($document, $user);
        }

        if ($url === null && $document->contractSigning?->isPending()) {
            $url = EmployeeContractSigner::openSigningUrl($document, $user);
        }

        if (! filled($url)) {
            $hasExternalPending = $document->documentSigning?->signers
                ->contains(fn ($s): bool => (bool) $s->is_external && blank($s->signed_at));

            Notification::make()
                ->title('Signing URL unavailable')
                ->body($hasExternalPending
                    ? 'No in-app Dokobit link for you. External invitees sign from their email link on Dokobit.'
                    : 'No pending Dokobit signing link was found for your user on this document.')
                ->warning()
                ->send();

            return false;
        }

        if (! method_exists($livewire, 'openDokobitSigningFrame')) {
            Notification::make()
                ->title('Dokobit modal unavailable')
                ->body('This page does not support in-app Dokobit signing.')
                ->danger()
                ->send();

            return false;
        }

        $livewire->openDokobitSigningFrame((string) $url, (int) $document->getKey());

        return true;
    }
}
