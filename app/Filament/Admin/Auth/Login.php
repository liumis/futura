<?php

namespace App\Filament\Admin\Auth;

use App\Filament\Admin\Pages\Dashboard;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): ?string
    {
        return Dashboard::getUrl();
    }
}
