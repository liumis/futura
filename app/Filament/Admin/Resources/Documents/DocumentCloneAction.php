<?php

namespace App\Filament\Admin\Resources\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentCloner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class DocumentCloneAction
{
    public static function make(string $name = 'clone'): Action
    {
        return Action::make($name)
            ->label('Clone')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Clone document')
            ->modalDescription(fn (Document $record): string => 'Create an editable copy of "'.$record->name.'"? Attachments are copied. Approvals and signatures are not.')
            ->modalSubmitActionLabel('Clone')
            ->visible(fn (Document $record): bool => ! $record->trashed())
            ->action(function (Document $record) {
                $user = auth()->user();
                if (! $user instanceof User) {
                    Notification::make()
                        ->title('Not authenticated')
                        ->danger()
                        ->send();

                    return null;
                }

                try {
                    $clone = DocumentCloner::clone($record, $user);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Could not clone document')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return null;
                }

                Notification::make()
                    ->title('Document cloned')
                    ->body('Open the copy to edit and sign when ready.')
                    ->success()
                    ->send();

                return redirect()->to(DocumentResource::getUrl('edit', ['record' => $clone]));
            });
    }
}
