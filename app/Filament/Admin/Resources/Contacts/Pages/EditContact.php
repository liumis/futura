<?php

namespace App\Filament\Admin\Resources\Contacts\Pages;

use App\Filament\Admin\Resources\Contacts\ContactResource;
use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Models\Contact;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * @return array<\Filament\Actions\Action | \Filament\Actions\ActionGroup>
     */
    protected function buildHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Invoices linked to this contact will be kept and unlinked (no company/supplier).')
                ->before(function (Contact $record): void {
                    $unlinked = $record->invoices()->count();
                    if ($unlinked <= 0) {
                        return;
                    }

                    // Actual unlink runs in Contact::deleting; notify the user here.
                    Notification::make()
                        ->title($unlinked === 1 ? '1 invoice will be unlinked' : "{$unlinked} invoices will be unlinked")
                        ->body('Those invoices stay in the system without this contact.')
                        ->warning()
                        ->send();
                }),
        ];
    }
}
