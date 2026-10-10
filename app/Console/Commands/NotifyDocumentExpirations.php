<?php

namespace App\Console\Commands;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Notifications\DocumentExpirationNotification;
use App\Services\DocumentExpirationMailer;
use App\Services\NotificationLogWriter;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class NotifyDocumentExpirations extends Command
{
    /**
     * Stage meanings:
     * - two_weeks: valid_to is 14 days from base date
     * - tomorrow:  valid_to is 1 day from base date
     */
    protected $signature = 'app:notify-document-expirations {--date= : Base date (Y-m-d). Defaults to today.}';

    protected $description = 'Notify selected document watchers about document expirations (in-app + email).';

    public function handle(): int
    {
        $base = filled((string) ($this->option('date') ?? ''))
            ? Carbon::parse((string) $this->option('date'))->startOfDay()
            : now()->startOfDay();

        $twoWeeksDate = $base->copy()->addDays(14)->toDateString();
        $tomorrowDate = $base->copy()->addDay()->toDateString();

        $sent = 0;
        $sent += $this->notifyStage('two_weeks', $twoWeeksDate);
        $sent += $this->notifyStage('tomorrow', $tomorrowDate);

        $this->info("Document expiration notifications processed: {$sent} user(s).");

        return self::SUCCESS;
    }

    /**
     * @param  'two_weeks'|'tomorrow'  $stage
     */
    protected function notifyStage(string $stage, string $validToDate): int
    {
        $documents = Document::query()
            ->whereDate('valid_to', $validToDate)
            ->with('watchers')
            ->get();

        if ($documents->isEmpty()) {
            return 0;
        }

        $stageLabel = $stage === 'tomorrow' ? 'Expiring tomorrow' : 'Expiring in 2 weeks';

        $sentUsers = 0;

        foreach ($documents as $document) {
            if ($document->watchers->isEmpty()) {
                continue;
            }

            $alreadySent = DB::table('document_expiration_notices')
                ->where('document_id', $document->getKey())
                ->where('stage', $stage)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            DB::table('document_expiration_notices')->insert([
                'document_id' => $document->getKey(),
                'stage' => $stage,
                'notified_at' => now(),
            ]);

            $url = DocumentResource::getUrl('edit', ['record' => $document]);

            foreach ($document->watchers as $user) {
                try {
                    $notification = new DocumentExpirationNotification(
                        documentId: (int) $document->getKey(),
                        documentName: (string) $document->name,
                        url: $url,
                        stageLabel: $stageLabel,
                        validToDateLabel: $validToDate,
                        stage: $stage,
                    );

                    $payload = $notification->toArray($user);
                    $metadata = [
                        'document_id' => (int) $document->getKey(),
                        'stage' => $stage,
                        'valid_to' => $validToDate,
                    ];

                    $user->notify($notification);

                    NotificationLogWriter::log(
                        recipient: $user,
                        channel: 'database',
                        notificationType: 'document_expiration',
                        title: (string) ($payload['title'] ?? 'Document expiration reminder'),
                        body: (string) ($payload['body'] ?? ''),
                        url: $url,
                        metadata: $metadata,
                    );

                    DocumentExpirationMailer::send(
                        recipient: $user,
                        document: $document,
                        stage: $stage,
                        validToDateLabel: $validToDate,
                        url: $url,
                    );

                    NotificationLogWriter::log(
                        recipient: $user,
                        channel: 'mail',
                        notificationType: 'document_expiration',
                        title: $stage === 'tomorrow'
                            ? 'Document expiring tomorrow: '.$document->name
                            : 'Document expiring in 2 weeks: '.$document->name,
                        body: 'Document: '.$document->name."\nValid to: ".$validToDate,
                        url: $url,
                        metadata: $metadata,
                    );

                    $sentUsers++;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $sentUsers;
    }
}
