<?php

namespace App\Services\Calendar;

final class CalendarSyncContext
{
    protected static bool $fromExternal = false;

    public static function isFromExternal(): bool
    {
        return self::$fromExternal;
    }

    public static function runningExternal(callable $callback): mixed
    {
        $previous = self::$fromExternal;
        self::$fromExternal = true;

        try {
            return $callback();
        } finally {
            self::$fromExternal = $previous;
        }
    }
}
