<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class EmailTestMode
{
    private const CACHE_KEY = 'system_settings.email_test_mode';

    public static function isEnabled(): bool
    {
        return (bool) Cache::remember(self::CACHE_KEY, 3600, function (): bool {
            return (bool) SystemSetting::query()->value('email_test_mode');
        });
    }

    public static function clearCache(): bool
    {
        return Cache::forget(self::CACHE_KEY);
    }

    public static function blockedMessage(): string
    {
        return 'Emails are in test mode. No email was sent.';
    }

    public static function ensureCanSend(): void
    {
        if (self::isEnabled()) {
            throw new \RuntimeException(self::blockedMessage());
        }
    }
}
