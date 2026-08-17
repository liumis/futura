<?php

namespace App\Listeners;

use App\Services\EmailTestMode;
use Illuminate\Mail\Events\MessageSending;

class PreventEmailSendingInTestMode
{
    public function handle(MessageSending $event): bool
    {
        if (EmailTestMode::isEnabled()) {
            return false;
        }

        return true;
    }
}
