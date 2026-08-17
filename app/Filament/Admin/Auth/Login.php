<?php

namespace App\Filament\Admin\Auth;

use App\Filament\Admin\Resources\Orders\OrderResource;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): ?string
    {
        return OrderResource::getUrl('index');
    }
}
