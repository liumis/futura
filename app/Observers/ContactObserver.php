<?php

namespace App\Observers;

use App\Models\Contact;
use App\Models\Invoice;

class ContactObserver
{
    public function saved(Contact $contact): void
    {
        if (! $contact->private_provider) {
            return;
        }

        if (! $contact->wasRecentlyCreated && ! $contact->wasChanged('private_provider')) {
            return;
        }

        Invoice::query()
            ->where('contact_id', $contact->getKey())
            ->where('private_provider', false)
            ->update(['private_provider' => true]);
    }
}
