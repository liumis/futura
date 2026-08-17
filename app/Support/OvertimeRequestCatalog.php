<?php

namespace App\Support;

final class OvertimeRequestCatalog
{
    /**
     * @var array<string, string> name => calendar color
     */
    public const TYPES = [
        'Viršvalandžiai' => '#0ea5e9',
        'Darbas poilsio dieną' => '#6366f1',
        'Darbas švenčių dieną' => '#db2777',
        'Kita' => '#64748b',
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TYPES);
    }
}
