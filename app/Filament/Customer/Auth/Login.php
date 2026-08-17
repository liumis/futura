<?php

namespace App\Filament\Customer\Auth;

use App\Filament\Customer\Resources\Orders\OrderResource;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): ?string
    {
        return OrderResource::getUrl('index');
    }
}
