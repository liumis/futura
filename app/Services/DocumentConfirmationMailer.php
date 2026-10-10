<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class DocumentConfirmationMailer
{
    public static function send(
        User $recipient,
        Document $document,
        string $dueAtLabel,
        string $url,
    ): void {
        $email = trim((string) ($recipient->email ?? ''));

        if ($email === '') {
            return;
        }

        $subject = 'Please confirm you have read: '.$document->name;

        $body = implode("\n", array_filter([
            'Document: '.$document->name,
            'Due: '.$dueAtLabel,
            '',
            'Please open the link below, view the document, and confirm that you have read it.',
            '',
            'Open: '.$url,
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
