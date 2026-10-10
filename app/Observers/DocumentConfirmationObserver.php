<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\DocumentConfirmationService;

class DocumentConfirmationObserver
{
    public function __construct(
        protected DocumentConfirmationService $confirmations,
    ) {}

    public function created(Document $document): void
    {
        $this->confirmations->enrollDocument($document);
    }

    public function updated(Document $document): void
    {
        if (! $document->wasChanged(['document_type_id', 'confirmation_audience'])) {
            return;
        }

        $this->confirmations->enrollDocument($document);
    }
}
