<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class InvoiceMailer
{
    public static function defaultRecipientEmail(Invoice $invoice): ?string
    {
        $invoice->loadMissing(['order.user', 'contact']);

        $customerEmail = trim((string) ($invoice->order?->user?->email ?? ''));

        if ($customerEmail !== '') {
            return $customerEmail;
        }

        $contactEmail = trim((string) ($invoice->contact?->contact_email ?? $invoice->contact?->company_email ?? ''));

        return $contactEmail !== '' ? $contactEmail : null;
    }

    public static function defaultSubject(Invoice $invoice): string
    {
        $number = filled($invoice->invoice_number)
            ? $invoice->invoice_number
            : 'invoice #'.$invoice->id;

        return 'Invoice '.$number;
    }

    public static function defaultBody(Invoice $invoice): string
    {
        $invoice->loadMissing(['order.user']);
        $customer = $invoice->order?->user;
        $name = trim(implode(' ', array_filter([$customer?->name, $customer?->surname])));
        $greeting = $name !== '' ? 'Hello '.$name.',' : 'Hello,';

        $lines = [
            $greeting,
            '',
        ];

        if (filled($invoice->invoice_number)) {
            $lines[] = 'Please find your invoice '.$invoice->invoice_number.'.';
        } else {
            $lines[] = 'Please find your invoice attached.';
        }

        if ($invoice->order_id) {
            $lines[] = 'Order #'.$invoice->order_id;
        }

        $lines[] = '';
        $lines[] = 'Thank you for your business.';

        return implode("\n", $lines);
    }

    public static function send(
        Invoice $invoice,
        string $email,
        string $subject,
        string $body,
        bool $attachInvoice,
        ?InvoiceLanguage $language = null,
    ): void {
        $email = trim($email);
        $subject = trim($subject);
        $body = trim($body);

        if ($email === '') {
            throw new \RuntimeException('Recipient email is required.');
        }

        if ($subject === '') {
            throw new \RuntimeException('Email subject is required.');
        }

        if ($body === '') {
            throw new \RuntimeException('Email text is required.');
        }

        EmailTestMode::ensureCanSend();

        $attachment = $attachInvoice ? self::resolveAttachment($invoice, $language) : null;

        $sentMessage = Mail::raw($body, function ($message) use ($email, $subject, $attachment): void {
            $message->getHeaders()->addTextHeader('X-Futura-Email-Log', 'handled');
            $message->to($email);
            $message->subject($subject);

            self::applyFrom($message);

            if ($attachment !== null) {
                $message->attachData(
                    $attachment['data'],
                    $attachment['name'],
                    ['mime' => $attachment['mime']],
                );
            }
        });

        if ($sentMessage === null) {
            throw new \RuntimeException('Email was not sent.');
        }

        $invoiceLabel = filled($invoice->invoice_number)
            ? $invoice->invoice_number
            : '#'.$invoice->id;

        EmailLogWriter::logFromSentMessage(
            $sentMessage,
            auth()->id(),
            config('mail.default'),
            'Invoice: '.$invoiceLabel.($invoice->order_id ? ' · Order #'.$invoice->order_id : ''),
        );
    }

    /**
     * @return array{data: string, name: string, mime: string}|null
     */
    private static function resolveAttachment(Invoice $invoice, ?InvoiceLanguage $language = null): ?array
    {
        $invoice->loadMissing(['order.user']);

        if ($invoice->order_id && $invoice->file_mime === 'application/pdf') {
            $language ??= InvoiceLanguage::normalize($invoice->order?->user?->invoice_language);
            $binary = InvoicePdfGenerator::generateForInvoice($invoice, $language);
            $suffix = strtoupper($language->value);
            $baseName = pathinfo((string) ($invoice->file_name ?: 'invoice.pdf'), PATHINFO_FILENAME);

            return [
                'data' => $binary,
                'name' => $baseName.'-'.$suffix.'.pdf',
                'mime' => 'application/pdf',
            ];
        }

        if (filled($invoice->file_content)) {
            $binary = base64_decode((string) $invoice->file_content, true);

            if ($binary !== false) {
                return [
                    'data' => $binary,
                    'name' => $invoice->file_name ?: 'invoice.pdf',
                    'mime' => $invoice->file_mime ?: 'application/octet-stream',
                ];
            }
        }

        if (filled($invoice->pdf_path) && \Illuminate\Support\Facades\Storage::disk('public')->exists($invoice->pdf_path)) {
            return [
                'data' => \Illuminate\Support\Facades\Storage::disk('public')->get($invoice->pdf_path),
                'name' => $invoice->file_name ?: basename($invoice->pdf_path),
                'mime' => $invoice->file_mime ?: 'application/pdf',
            ];
        }

        throw new \RuntimeException('No invoice file is available to attach.');
    }

    private static function applyFrom(mixed $message): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new \RuntimeException('You must be logged in to send invoice emails.');
        }

        if (blank($user->email)) {
            throw new \RuntimeException('Your user account has no email address.');
        }

        $name = trim(implode(' ', array_filter([$user->name, $user->surname])));

        $message->from(
            $user->email,
            $name !== '' ? $name : (string) $user->email,
        );
    }
}
