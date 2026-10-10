<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class DocumentExpirationMailer
{
    /**
     * @param  'two_weeks'|'tomorrow'  $stage
     */
    public static function send(
        User $recipient,
        Document $document,
        string $stage,
        string $validToDateLabel,
        string $url,
    ): void {
        $email = trim((string) ($recipient->email ?? ''));

        if ($email === '') {
            return;
        }

        $stageLabel = $stage === 'tomorrow' ? 'Tomorrow' : 'In 2 weeks';
        $subject = $stage === 'tomorrow'
            ? 'Document expiring tomorrow: '.$document->name
            : 'Document expiring in 2 weeks: '.$document->name;

        $body = implode("\n", array_filter([
            'Document: '.$document->name,
            'Valid to: '.$validToDateLabel,
            $stageLabel.' reminder',
            '',
            'Open in admin: '.$url,
        ], static fn (string $line): bool => trim($line) !== ''));

        if (EmailTestMode::isEnabled()) {
            EmailLogWriter::logManual(
                to: $email,
                subject: $subject,
                body: $body,
                from: self::fromAddress()['formatted'],
                bodyAppendix: EmailTestMode::blockedMessage(),
            );

            return;
        }

        $from = self::fromAddress();

        Mail::raw($body, function ($message) use ($email, $subject, $from): void {
            $message->to($email)->subject($subject);
            $message->from($from['address'], $from['name']);
        });
    }

    /**
     * @return array{address: string, name: string, formatted: string}
     */
    protected static function fromAddress(): array
    {
        $address = (string) config('mail.from.address');
        $name = (string) (config('mail.from.name') ?: $address);

        return [
            'address' => $address,
            'name' => $name,
            'formatted' => sprintf('%s <%s>', $name, $address),
        ];
    }
}
