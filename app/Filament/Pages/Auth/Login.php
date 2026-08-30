<?php

namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login as BaseLogin;

class Login extends BaseLogin
{
    protected static string $view = 'filament.pages.auth.custom-login';

    public function getHeading(): string
    {
        return 'Institutional Sign In';
    }

    public function getSubheading(): ?string
    {
        return 'Enter your academic credentials to access the FASRE QA portal.';
    }
}
