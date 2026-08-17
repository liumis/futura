<?php

namespace App\Support;

use Filament\Panel;

final class PanelHosts
{
    public static function applyAdmin(Panel $panel): Panel
    {
        $domain = config('panels.admin_domain');

        if (filled($domain)) {
            return $panel->domain((string) $domain)->path('');
        }

        return $panel->path('');
    }

    public static function applyCustomer(Panel $panel): Panel
    {
        $domain = config('panels.customer_domain');

        if (filled($domain)) {
            return $panel->domain((string) $domain)->path('');
        }

        // Local / single-host: customers live under /customer
        return $panel->path('customer');
    }

    public static function usesSeparateDomains(): bool
    {
        return filled(config('panels.admin_domain'))
            && filled(config('panels.customer_domain'));
    }
}
