<?php

namespace App\Support;

use App\Models\LtHoliday;

final class LtOfficialHolidays
{
    /**
     * @return list<array{
     *     recurrence_key: string,
     *     name: string,
     *     month?: int,
     *     day?: int,
     *     easter_offset?: int,
     * }>
     */
    public static function definitions(): array
    {
        return [
            [
                'recurrence_key' => 'fixed:1:1',
                'month' => 1,
                'day' => 1,
                'name' => 'Naujieji metai',
            ],
            [
                'recurrence_key' => 'fixed:2:16',
                'month' => 2,
                'day' => 16,
                'name' => 'Lietuvos valstybės atkūrimo diena',
            ],
            [
                'recurrence_key' => 'fixed:3:11',
                'month' => 3,
                'day' => 11,
                'name' => 'Lietuvos nepriklausomybės atkūrimo diena',
            ],
            [
                'recurrence_key' => 'fixed:5:1',
                'month' => 5,
                'day' => 1,
                'name' => 'Tarptautinė darbo diena',
            ],
            [
                'recurrence_key' => 'fixed:6:24',
                'month' => 6,
                'day' => 24,
                'name' => 'Rasos ir Joninių šventė',
            ],
            [
                'recurrence_key' => 'fixed:7:6',
                'month' => 7,
                'day' => 6,
                'name' => 'Valstybės (karaliaus Mindaugo karūnavimo) diena',
            ],
            [
                'recurrence_key' => 'fixed:8:15',
                'month' => 8,
                'day' => 15,
                'name' => 'Žolinė',
            ],
            [
                'recurrence_key' => 'fixed:11:1',
                'month' => 11,
                'day' => 1,
                'name' => 'Visų šventųjų diena',
            ],
            [
                'recurrence_key' => 'fixed:12:24',
                'month' => 12,
                'day' => 24,
                'name' => 'Kūčios',
            ],
            [
                'recurrence_key' => 'fixed:12:25',
                'month' => 12,
                'day' => 25,
                'name' => 'Kalėdos',
            ],
            [
                'recurrence_key' => 'fixed:12:26',
                'month' => 12,
                'day' => 26,
                'name' => 'Kalėdų antra diena',
            ],
            [
                'recurrence_key' => 'easter:1',
                'easter_offset' => 1,
                'name' => 'Velykų antroji diena',
            ],
        ];
    }

    public static function seed(): int
    {
        $created = 0;

        foreach (self::definitions() as $definition) {
            $record = LtHoliday::query()->updateOrCreate(
                ['recurrence_key' => $definition['recurrence_key']],
                [
                    'name' => $definition['name'],
                    'month' => $definition['month'] ?? null,
                    'day' => $definition['day'] ?? null,
                    'easter_offset' => $definition['easter_offset'] ?? null,
                ],
            );

            if ($record->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }
}
