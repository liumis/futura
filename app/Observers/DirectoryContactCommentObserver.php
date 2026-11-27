<?php

namespace App\Observers;

use App\Filament\Admin\Pages\ContactsBoard;
use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Models\DirectoryContactComment;
use App\Models\User;
use App\Notifications\DirectoryContactCommentNotification;
use Illuminate\Support\Str;
use Throwable;

class DirectoryContactCommentObserver
{
    public function created(DirectoryContactComment $comment): void
    {
        try {
            $comment->loadMissing(['directoryContact', 'user']);

            $contact = $comment->directoryContact;
            if ($contact === null) {
                return;
            }

            $authorId = $comment->user_id !== null ? (int) $comment->user_id : null;

            $recipientIds = DirectoryContactComment::query()
                ->where('directory_contact_id', $comment->directory_contact_id)
                ->whereNotNull('user_id')
                ->when(
                    $authorId !== null,
                    fn ($query) => $query->where('user_id', '!=', $authorId),
                )
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if ($recipientIds === []) {
                return;
            }

            $contactName = (string) ($contact->company_name ?? 'Contact #'.$contact->getKey());
            $preview = Str::limit(trim((string) ($comment->comment ?? '')), 120);

            try {
                $url = DirectoryContactResource::getUrl('edit', ['record' => $contact]);
            } catch (Throwable) {
                $url = ContactsBoard::getUrl();
            }

            $notification = new DirectoryContactCommentNotification(
                directoryContactId: (int) $contact->getKey(),
                contactName: $contactName,
                commentPreview: $preview,
                url: $url,
            );

            User::query()
                ->whereIn('id', $recipientIds)
                ->get()
                ->each(fn (User $user) => $user->notify($notification));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
