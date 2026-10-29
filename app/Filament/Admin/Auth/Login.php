<?php

namespace App\Filament\Admin\Auth;

use App\Services\AdminHomepage;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    protected function getRedirectUrl(): ?string
    {
        return AdminHomepage::urlFor(auth()->user());
    }
}
