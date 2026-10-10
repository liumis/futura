<?php

namespace App\Services;

use App\Models\Cargo;
use App\Models\CustomsContact;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class CustomsContactMailer
{
    public static function buildBody(CustomsContact $contact, Cargo $cargo): string
    {
        $contact->loadMissing('mailTemplate');
        $cargo->loadMissing('supplier');

        $templateText = trim((string) ($contact->mailTemplate?->text ?? ''));

        if ($templateText === '') {
            return self::defaultBody($cargo, $contact);
        }

        return self::applyPlaceholders($templateText, $cargo, $contact);
    }

    public static function preview(?int $customsContactId, Cargo $cargo): string
    {
        $contact = CustomsContact::query()
            ->with('mailTemplate')
            ->find($customsContactId);

        if ($contact === null) {
            return 'No customs contact selected.';
        }

        return self::buildBody($contact, $cargo);
    }

    public static function resolveSubject(CustomsContact $contact, Cargo $cargo): string
    {
        $contact->loadMissing('mailTemplate');
        $subject = trim((string) ($contact->mailTemplate?->subject ?? ''));

        if ($subject === '') {
            return 'Customs documents — warehouse order #'.$cargo->id;
        }

        return self::applyPlaceholders($subject, $cargo, $contact);
    }

    /**
     * @param  bool  $attachDocuments  Download customs documents from SharePoint and attach them
     */
    public static function send(
        Cargo $cargo,
        CustomsContact $contact,
        ?string $body = null,
        bool $attachDocuments = true,
    ): void {
        $contact->loadMissing('mailTemplate');
        $cargo->loadMissing(['supplier', 'customsContact']);

        if (blank($contact->email)) {
            throw new \RuntimeException('The selected customs contact has no email address.');
        }

        $body = filled($body) ? trim($body) : self::buildBody($contact, $cargo);

        if ($body === '') {
            throw new \RuntimeException('Email text is empty.');
        }

        EmailTestMode::ensureCanSend();

        $attachments = $attachDocuments ? self::resolveAttachments($cargo) : [];

        Mail::raw($body, function ($message) use ($cargo, $contact, $attachments): void {
            $message->to((string) $contact->email);
            self::applyFrom($message, $contact);
            $message->subject(self::resolveSubject($contact, $cargo));

            foreach ($attachments as $attachment) {
                $message->attachData(
                    $attachment['data'],
                    $attachment['name'],
                    ['mime' => $attachment['mime']],
                );
            }
        });

        $cargo->forceFill([
            'customs_contact_id' => $contact->getKey(),
            'customs_email_sent_at' => now(),
        ])->save();
    }

    private static function defaultBody(Cargo $cargo, CustomsContact $contact): string
    {
        $lines = [
            'Hello '.$contact->company_name.',',
            '',
            'Please find the customs documents for warehouse order #'.$cargo->id.'.',
        ];

        if (filled($cargo->tracking)) {
            $lines[] = 'Tracking: '.$cargo->tracking;
        }

        if ($cargo->estimated_arrival) {
            $lines[] = 'Estimated arrival: '.$cargo->estimated_arrival->format('Y-m-d');
        }

        $supplierName = trim((string) ($cargo->supplier?->name ?? ''));
        if ($supplierName !== '') {
            $lines[] = 'Supplier: '.$supplierName;
        }

        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    private static function applyPlaceholders(string $text, Cargo $cargo, CustomsContact $contact): string
    {
        $cargo->loadMissing('supplier');

        return str_replace(
            [
                '{order_id}',
                '{tracking}',
                '{estimated_arrival}',
                '{supplier}',
                '{company_name}',
            ],
            [
                (string) $cargo->id,
                (string) ($cargo->tracking ?? ''),
                $cargo->estimated_arrival?->format('Y-m-d') ?? '',
                (string) ($cargo->supplier?->name ?? ''),
                (string) ($contact->company_name ?? ''),
            ],
            $text,
        );
    }

    /**
     * @return list<array{data: string, name: string, mime: string}>
     */
    private static function resolveAttachments(Cargo $cargo): array
    {
        $docs = $cargo->customsDocumentsList();

        if ($docs === []) {
            return [];
        }

        $client = SharepointGraphClient::make();
        $attachments = [];

        foreach ($docs as $doc) {
            $itemId = $doc['item_id'] ?? null;

            if (! filled($itemId)) {
                continue;
            }

            $binary = $client->downloadItemContent((string) $itemId);
            $name = (string) ($doc['original_name'] ?? $doc['name'] ?? 'document.pdf');
            $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            $attachments[] = [
                'data' => $binary,
                'name' => $name,
                'mime' => self::mimeForExtension($ext),
            ];
        }

        return $attachments;
    }

    private static function mimeForExtension(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    private static function applyFrom(mixed $message, CustomsContact $contact): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new \RuntimeException('You must be logged in to send customs emails.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Your user account has no email address.');
        }

        $userName = self::senderDisplayName($user);
        $templateFromName = trim((string) ($contact->mailTemplate?->from_name ?? ''));

        $fromName = $templateFromName !== ''
            ? $templateFromName.' | '.$userName
            : $userName;

        $message->from($user->email, $fromName);
    }

    private static function senderDisplayName(User $user): string
    {
        $name = trim(implode(' ', array_filter([$user->name, $user->surname])));

        if ($name !== '') {
            return $name;
        }

        return (string) $user->email;
    }
}
