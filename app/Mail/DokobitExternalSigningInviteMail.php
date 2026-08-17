<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\DocumentSigningSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DokobitExternalSigningInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Document $document,
        public DocumentSigningSigner $signer,
        public string $signingUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Please sign: '.$this->document->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.dokobit-external-signing-invite',
            with: [
                'document' => $this->document,
                'signer' => $this->signer,
                'signingUrl' => $this->signingUrl,
                'appName' => config('app.name', 'FuturaTextiles SS'),
            ],
        );
    }
}
