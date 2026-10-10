<?php

namespace App\Console\Commands;

use App\Filament\Admin\Resources\DocumentConfirmations\DocumentConfirmationResource;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\DocumentConfirmation;
use App\Notifications\DocumentConfirmationNotification;
use App\Services\DocumentConfirmationMailer;
use App\Services\NotificationLogWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class NotifyDocumentConfirmations extends Command
{
    protected $signature = 'app:notify-document-confirmations {--date= : Base date (Y-m-d). Defaults to today.}';

    protected $description = 'Notify users once when a document confirmation cycle is due (in-app + email).';

    public function handle(): int
    {
        $base = filled((string) ($this->option('date') ?? ''))
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $confirmations = DocumentConfirmation::query()
            ->dueForNotification($base)
            ->with(['document', 'user'])
            ->get();

        $sent = 0;

        foreach ($confirmations as $confirmation) {
            $document = $confirmation->document;
            $user = $confirmation->user;

            if ($document === null || $user === null) {
                continue;
            }

            try {
                $url = DocumentResource::getUrl('edit', ['record' => $document]);
            } catch (Throwable) {
                $url = DocumentConfirmationResource::getUrl('index');
            }

            $dueLabel = $confirmation->due_at?->toDateString() ?? $base->toDateString();

            $notification = new DocumentConfirmationNotification(
                documentId: (int) $document->getKey(),
                documentName: (string) $document->name,
                url: $url,
                dueAtLabel: $dueLabel,
            );

            $payload = $notification->toArray($user);
            $metadata = [
                'document_id' => (int) $document->getKey(),
                'document_confirmation_id' => (int) $confirmation->getKey(),
                'due_at' => $dueLabel,
            ];

            try {
                $user->notify($notification);

                NotificationLogWriter::log(
                    recipient: $user,
                    channel: 'database',
                    notificationType: 'document_confirmation',
                    title: (string) ($payload['title'] ?? 'Document confirmation required'),
                    body: (string) ($payload['body'] ?? ''),
                    url: $url,
                    metadata: $metadata,
                );

                DocumentConfirmationMailer::send(
                    recipient: $user,
                    document: $document,
                    dueAtLabel: $dueLabel,
                    url: $url,
                );

                NotificationLogWriter::log(
                    recipient: $user,
                    channel: 'mail',
                    notificationType: 'document_confirmation',
                    title: 'Please confirm you have read: '.$document->name,
                    body: 'Document: '.$document->name."\nDue: ".$dueLabel,
                    url: $url,
                    metadata: $metadata,
                );

                $confirmation->notified_at = now();
                $confirmation->save();

                $sent++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Document confirmation notifications processed: {$sent} user(s).");

        return self::SUCCESS;
    }
}
