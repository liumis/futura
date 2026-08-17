<?php

namespace App\Support;

final class LeaveRequestCatalog
{
    /**
     * Leave / document type names shown in the leave request UI.
     *
     * @var array<string, string> name => calendar color
     */
    public const TYPES = [
        'Kasmetinės atostogos' => '#3b82f6',
        'Nedarbingumas' => '#ef4444',
        'Neapmokamos atostogos' => '#f97316',
        'Tėvadienis / Mamadienis' => '#a855f7',
        'Komandiruotė' => '#14b8a6',
        'Darbas nuotoliu' => '#22c55e',
        'Kita' => '#6b7280',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TYPES);
    }
}
