<?php

namespace App\Support;

/**
 * Official Lithuanian work-time timesheet codes
 * (Darbo laiko apskaitos žiniaraščio sutartinis žymėjimas).
 */
final class TimesheetCode
{
    /**
     * @var array<string, string> code => Lithuanian label
     */
    public const DEFINITIONS = [
        'ID' => 'Laikas naujo darbo paieškoms',
        'MD' => 'Privalomų medicininių apžiūrų laikas',
        'V' => 'Papildomos poilsio dienos už viršvalandžius / darbą poilsio ir švenčių dienomis',
        'M' => 'Papildomas poilsio laikas auginantiems neįgalų vaiką iki 16 m. arba ≥2 vaikus iki 14 m.',
        'D' => 'Kraujo davimo dienos donorams',
        'L' => 'Nedarbingumas dėl ligos ar traumų',
        'N' => 'Neapmokamas nedarbingumas',
        'NS' => 'Nedarbingumas ligoniams slaugyti, turint pažymas',
        'A' => 'Kasmetinės atostogos',
        'MA' => 'Mokymosi atostogos',
        'NA' => 'Nemokamos atostogos',
        'KA' => 'Kūrybinės atostogos',
        'G' => 'Nėštumo ir gimdymo atostogos',
        'PV' => 'Atostogos vaikui prižiūrėti, kol jam sueis 3 metai',
        'KR' => 'Kitų rūšių atostogos',
        'K' => 'Tarnybinės komandiruotės',
        'KK' => 'Komandiruotės kelyje',
        'KV' => 'Kvalifikacijos kėlimas',
        'VV' => 'Valstybinių, visuomeninių ar piliečio pareigų vykdymas',
        'KT' => 'Karinė tarnyba',
        'KM' => 'Mokomosios karinės pratybos',
        'PK' => 'Prastovos dėl darbuotojo kaltės',
        'PN' => 'Prastovos ne dėl darbuotojo kaltės',
        'PB' => 'Pravaikštos ir kitoks neatvykimas be svarbios priežasties',
        'NP' => 'Neatvykimas į darbą kitais norminių teisės aktų nustatytais laikotarpiais',
        'NN' => 'Nušalinimas nuo darbo',
        'P' => 'Poilsio dienos',
        'S' => 'Švenčių dienos',
        'ST' => 'Streikas',
        'TA' => 'Tėvystės atostogos',
    ];

    /**
     * Codes shown on the timesheet legend (official order).
     *
     * @return list<string>
     */
    public static function legendCodes(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Codes that may be assigned to leave request types
     * (excludes calendar-driven P / S).
     *
     * @return array<string, string>
     */
    public static function assignableOptions(): array
    {
        $options = [];

        foreach (self::DEFINITIONS as $code => $label) {
            if (in_array($code, ['P', 'S'], true)) {
                continue;
            }

            $options[$code] = $code.' — '.$label;
        }

        return $options;
    }

    public static function label(string $code): ?string
    {
        return self::DEFINITIONS[$code] ?? null;
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::DEFINITIONS);
    }

    public static function normalize(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $normalized = strtoupper(trim($code));

        return self::isValid($normalized) ? $normalized : null;
    }
}
