<?php

namespace App\Services;

use App\Models\EmailLog;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class EmailLogWriter
{
    /**
     * @param  array<int, string>|string  $to
     * @param  array<int, string>|string|null  $from
     */
    public static function logManual(
        array|string $to,
        string $subject,
        string $body,
        array|string|null $from = null,
        ?int $userId = null,
        ?string $mailer = null,
        ?string $bodyAppendix = null,
    ): EmailLog {
        $toList = is_array($to) ? array_values($to) : [trim($to)];
        $fromList = $from === null
            ? null
            : (is_array($from) ? array_values($from) : [trim((string) $from)]);

        if (filled($bodyAppendix)) {
            $body = trim($body);
            $body .= ($body !== '' ? "\n\n" : '').trim($bodyAppendix);
        }

        return EmailLog::query()->create([
            'user_id' => $userId ?? auth()->id(),
            'from' => $fromList,
            'to' => $toList,
            'cc' => null,
            'bcc' => null,
            'subject' => $subject,
            'body' => $body,
            'mailer' => $mailer ?? config('mail.default'),
        ]);
    }

    public static function logFromSentMessage(
        SentMessage $sentMessage,
        ?int $userId = null,
        ?string $mailer = null,
        ?string $bodyAppendix = null,
    ): EmailLog {
        return self::logFromSymfonyEmail(
            $sentMessage->getOriginalMessage(),
            $userId,
            $mailer,
            $bodyAppendix,
        );
    }

    public static function logFromSymfonyEmail(
        Email $message,
        ?int $userId = null,
        ?string $mailer = null,
        ?string $bodyAppendix = null,
    ): EmailLog {
        $body = $message->getTextBody();

        if (blank($body) && filled($message->getHtmlBody())) {
            $body = trim(html_entity_decode(strip_tags((string) $message->getHtmlBody())));
        }

        $attachmentNames = collect($message->getAttachments())
            ->map(fn (DataPart $attachment): string => $attachment->getFilename() ?: 'attachment')
            ->filter()
            ->values()
            ->all();

        if ($attachmentNames !== []) {
            $body = trim((string) $body);
            $body .= ($body !== '' ? "\n\n" : '').'[Attachments: '.implode(', ', $attachmentNames).']';
        }

        if (filled($bodyAppendix)) {
            $body = trim((string) $body);
            $body .= ($body !== '' ? "\n\n" : '').trim($bodyAppendix);
        }

        return EmailLog::query()->create([
            'user_id' => $userId ?? auth()->id(),
            'from' => self::formatAddresses($message->getFrom()),
            'to' => self::formatAddresses($message->getTo()) ?? [],
            'cc' => self::formatAddresses($message->getCc()),
            'bcc' => self::formatAddresses($message->getBcc()),
            'subject' => $message->getSubject(),
            'body' => $body,
            'mailer' => $mailer ?? config('mail.default'),
        ]);
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>|null
     */
    private static function formatAddresses(array $addresses): ?array
    {
        if ($addresses === []) {
            return null;
        }

        return array_map(
            fn (Address $address): string => $address->toString(),
            $addresses,
        );
    }
}
