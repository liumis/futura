<?php

namespace App\Support;

final class LeaveRequestCatalog
{
    /**
     * Leave / document type names shown in the leave request UI.
     *
     * @var array<string, array{color: string, timesheet_code: string|null}>
     */
    public const TYPES = [
        'Kasmetinės atostogos' => [
            'color' => '#3b82f6',
            'timesheet_code' => 'A',
        ],
        'Nedarbingumas' => [
            'color' => '#ef4444',
            'timesheet_code' => 'L',
        ],
        'Neapmokamos atostogos' => [
            'color' => '#f97316',
            'timesheet_code' => 'NA',
        ],
        'Tėvadienis / Mamadienis' => [
            'color' => '#a855f7',
            'timesheet_code' => 'M',
        ],
        'Komandiruotė' => [
            'color' => '#14b8a6',
            'timesheet_code' => 'K',
        ],
        'Darbas nuotoliu' => [
            'color' => '#22c55e',
            'timesheet_code' => null,
        ],
        'Kita' => [
            'color' => '#6b7280',
            'timesheet_code' => 'KR',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TYPES);
    }
}
