<?php

namespace App\Filament\Customer\Auth;

use App\Filament\Customer\Resources\Orders\OrderResource;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): ?string
    {
        return OrderResource::getUrl('index');
    }

    public function getHeading(): string | Htmlable | null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        // Wordmark on the login card is enough — "Sign in" is redundant.
        return null;
    }
}
