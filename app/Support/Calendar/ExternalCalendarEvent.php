<?php

namespace App\Support\Calendar;

use Carbon\CarbonInterface;

final class ExternalCalendarEvent
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $subject,
        public readonly ?CarbonInterface $start,
        public readonly ?CarbonInterface $end,
        public readonly bool $allDay,
        public readonly ?string $changeKey = null,
        public readonly ?CarbonInterface $lastModified = null,
        public readonly ?string $rawICalUId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromMicrosoftGraph(array $data): self
    {
        $allDay = (bool) ($data['isAllDay'] ?? false);
        $start = self::parseGraphDateTime($data['start'] ?? null, $allDay);
        $end = self::parseGraphDateTime($data['end'] ?? null, $allDay);

        $modified = null;
        if (filled($data['lastModifiedDateTime'] ?? null)) {
            $modified = \Illuminate\Support\Carbon::parse((string) $data['lastModifiedDateTime'])->utc();
        }

        return new self(
            id: (string) ($data['id'] ?? ''),
            subject: isset($data['subject']) ? (string) $data['subject'] : null,
            start: $start,
            end: $end,
            allDay: $allDay,
            changeKey: isset($data['changeKey']) ? (string) $data['changeKey'] : null,
            lastModified: $modified,
            rawICalUId: isset($data['iCalUId']) ? (string) $data['iCalUId'] : null,
        );
    }

    /**
     * @param  array{dateTime?: string, date?: string, timeZone?: string}|null  $value
     */
    protected static function parseGraphDateTime(?array $value, bool $allDay): ?\Illuminate\Support\Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($allDay && filled($value['date'] ?? null)) {
            return \Illuminate\Support\Carbon::parse((string) $value['date'], 'UTC')->startOfDay();
        }

        if (filled($value['dateTime'] ?? null)) {
            $tz = filled($value['timeZone'] ?? null) ? (string) $value['timeZone'] : 'UTC';

            return \Illuminate\Support\Carbon::parse((string) $value['dateTime'], $tz)->utc();
        }

        if (filled($value['date'] ?? null)) {
            return \Illuminate\Support\Carbon::parse((string) $value['date'], 'UTC')->startOfDay();
        }

        return null;
    }
}
