<?php

namespace App\Services;

use App\Enums\DirectoryContactPriority;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactComment;
use App\Models\DirectoryContactPerson;
use App\Models\DirectoryContactStage;
use App\Models\OriginCountry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

class DirectoryContactExcelImporter
{
    /**
     * @return array{created: int, updated: int, skipped: int, persons: int, comments: int, file_name: string}
     */
    public function import(string $path, ?string $fileName = null, ?int $userId = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Excel file was not found.');
        }

        $fileName = $fileName ?: basename($path);
        $rows = $this->parseRows($path);

        if ($rows === []) {
            throw new RuntimeException('No contact rows were found in the Excel file.');
        }

        $userId ??= auth()->id();
        $stages = DirectoryContactStage::query()->get()->keyBy(
            fn (DirectoryContactStage $stage): string => Str::lower(trim($stage->name)),
        );
        $countries = OriginCountry::query()
            ->pluck('name')
            ->mapWithKeys(fn (string $name): array => [Str::lower($name) => $name])
            ->all();

        $existingContacts = DirectoryContact::query()->with('comments')->get();
        $existingByCompany = $existingContacts->keyBy(
            fn (DirectoryContact $contact): string => $this->companyKey($contact->company_name)
        );
        $existingByEmail = collect();
        $existingByPhone = collect();
        $existingByAddress = collect();

        foreach ($existingContacts as $contact) {
            foreach ($this->identityEmails($contact->email) as $email) {
                $existingByEmail[$email] = $contact;
            }
            foreach ($this->identityPhones($contact->phone) as $phone) {
                $existingByPhone[$phone] = $contact;
            }
            foreach ($contact->comments as $comment) {
                $address = $this->extractAddressKey((string) $comment->comment);
                if ($address !== '') {
                    $existingByAddress[$address] = $contact;
                }
            }
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $persons = 0;
        $comments = 0;

        DB::transaction(function () use (
            $rows,
            $userId,
            &$stages,
            $countries,
            &$existingByCompany,
            &$existingByEmail,
            &$existingByPhone,
            &$existingByAddress,
            &$created,
            &$updated,
            &$skipped,
            &$persons,
            &$comments,
        ): void {
            foreach ($rows as $row) {
                $company = $row['company_name'];
                if ($company === '') {
                    $skipped++;

                    continue;
                }

                $key = $this->companyKey($company);
                $payload = [
                    'company_name' => $company,
                    'type' => $row['type'] ?: null,
                    'country' => $this->resolveCountry($row['country'], $countries),
                    'segment' => $row['segment'] ?: null,
                    'priority' => $row['priority'],
                    'website' => $row['website'],
                    'email' => $row['email'] ?: null,
                    'phone' => $row['phone'] ?: null,
                    'directory_contact_stage_id' => $this->resolveStageId($row['stage'], $stages),
                    'last_contact' => $row['last_contact'],
                    'next_contact' => $row['next_contact'],
                ];

                /** @var DirectoryContact|null $contact */
                $contact = $this->findExistingContact(
                    $row,
                    $key,
                    $existingByCompany,
                    $existingByEmail,
                    $existingByPhone,
                    $existingByAddress,
                );

                if ($contact) {
                    $contact->fill($this->mergeContactPayload($contact, $payload))->save();
                    $updated++;
                } else {
                    $payload['directory_contact_stage_id'] ??= DirectoryContactStage::defaultId();
                    $contact = DirectoryContact::query()->create($payload);
                    $created++;
                }

                $this->indexContact(
                    $contact,
                    $existingByCompany,
                    $existingByEmail,
                    $existingByPhone,
                    $existingByAddress,
                    $row['comment'],
                );

                if ($row['comment'] !== '') {
                    $already = $contact->comments()
                        ->where('comment', $row['comment'])
                        ->exists();

                    if (! $already) {
                        DirectoryContactComment::query()->create([
                            'directory_contact_id' => $contact->id,
                            'user_id' => $userId,
                            'commented_at' => $row['last_contact']
                                ? Carbon::parse($row['last_contact'])->startOfDay()
                                : now(),
                            'comment' => $row['comment'],
                        ]);
                        $comments++;
                    }
                }

                if ($row['contact_name'] !== '' || $row['contact_email'] !== '' || $row['contact_phone'] !== '') {
                    [$firstName, $surname] = $this->splitPersonName($row['contact_name']);

                    $personQuery = $contact->contactPersons();
                    $person = null;

                    if ($row['contact_email'] !== '') {
                        $person = (clone $personQuery)->where('email', $row['contact_email'])->first();
                    }

                    if (! $person && $row['contact_phone'] !== '') {
                        $normalizedPhone = $this->phoneKey($row['contact_phone']);
                        $person = (clone $personQuery)->get()->first(
                            fn (DirectoryContactPerson $candidate): bool => $this->phoneKey((string) $candidate->phone) === $normalizedPhone
                                && $normalizedPhone !== ''
                        );
                    }

                    if (! $person && $firstName !== '') {
                        $person = (clone $personQuery)
                            ->where('name', $firstName)
                            ->where('surname', $surname)
                            ->first();
                    }

                    $personPayload = [
                        'directory_contact_id' => $contact->id,
                        'name' => $firstName !== '' ? $firstName : 'Contact',
                        'surname' => $surname !== '' ? $surname : '-',
                        'phone' => $row['contact_phone'] ?: null,
                        'email' => $row['contact_email'] ?: null,
                        'comment' => $row['contact_note'] ?: null,
                    ];

                    if ($person) {
                        $person->fill($this->mergePersonPayload($person, $personPayload))->save();
                    } else {
                        DirectoryContactPerson::query()->create($personPayload);
                        $persons++;
                    }
                }
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'persons' => $persons,
            'comments' => $comments,
            'file_name' => $fileName,
        ];
    }

    /**
     * @return list<array{
     *     company_name: string,
     *     type: string,
     *     country: string,
     *     segment: string,
     *     priority: ?DirectoryContactPriority,
     *     website: ?string,
     *     email: string,
     *     phone: string,
     *     stage: string,
     *     last_contact: ?string,
     *     next_contact: ?string,
     *     comment: string,
     *     contact_name: string,
     *     contact_phone: string,
     *     contact_email: string,
     *     contact_note: string
     * }>
     */
    protected function parseRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = (int) $sheet->getHighestDataRow();

            if ($highestRow < 2) {
                return [];
            }

            $headers = [];
            foreach ($sheet->getRowIterator(1, 1) as $headerRow) {
                foreach ($headerRow->getCellIterator() as $cell) {
                    $col = $cell->getColumn();
                    $headers[$col] = Str::lower(trim((string) $cell->getValue()));
                }
            }

            $map = [
                'company name' => 'company_name',
                'company' => 'company_name',
                'type' => 'type',
                'country' => 'country',
                'segment' => 'segment',
                'priority' => 'priority',
                'website' => 'website',
                'email' => 'email',
                'phone' => 'phone',
                'stage' => 'stage',
                'last contact' => 'last_contact',
                'next contact' => 'next_contact',
                'comment' => 'comment',
                'contact name' => 'contact_name',
                'contact phone' => 'contact_phone',
                'contact email' => 'contact_email',
                'contact note' => 'contact_note',
            ];

            $colByField = [];
            foreach ($headers as $col => $header) {
                if (isset($map[$header])) {
                    $colByField[$map[$header]] = $col;
                }
            }

            if (! isset($colByField['company_name'])) {
                throw new RuntimeException('Excel must include a "Company name" column.');
            }

            $rows = [];
            for ($r = 2; $r <= $highestRow; $r++) {
                $get = function (string $field) use ($sheet, $colByField, $r): mixed {
                    $col = $colByField[$field] ?? null;
                    if ($col === null) {
                        return null;
                    }

                    return $sheet->getCell($col.$r)->getCalculatedValue();
                };

                $company = trim((string) ($get('company_name') ?? ''));
                if ($company === '') {
                    continue;
                }

                $websiteRaw = $this->cellString($get('website'));
                $rows[] = [
                    'company_name' => $company,
                    'type' => $this->cellString($get('type')),
                    'country' => $this->cellString($get('country')),
                    'segment' => $this->cellString($get('segment')),
                    'priority' => $this->normalizePriority($this->cellString($get('priority'))),
                    'website' => $this->normalizeWebsite($websiteRaw),
                    'email' => Str::lower($this->cellString($get('email'))),
                    'phone' => $this->cellString($get('phone')),
                    'stage' => $this->cellString($get('stage')),
                    'last_contact' => $this->normalizeDate($get('last_contact')),
                    'next_contact' => $this->normalizeDate($get('next_contact')),
                    'comment' => $this->cellString($get('comment')),
                    'contact_name' => $this->cellString($get('contact_name')),
                    'contact_phone' => $this->cellString($get('contact_phone')),
                    'contact_email' => Str::lower($this->cellString($get('contact_email'))),
                    'contact_note' => $this->cellString($get('contact_note')),
                ];
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    protected function companyKey(string $name): string
    {
        $name = Str::lower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
        $name = preg_replace('/\b(ltd|limited|gmbh|as|uab|ab|sa|srl|spa|inc|llc|oy|bv|ag|plc|bros)\b\.?/u', '', $name) ?? $name;
        $name = trim(preg_replace('/[^a-z0-9]+/u', ' ', $name) ?? $name);

        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    protected function phoneKey(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 7 ? $digits : '';
    }

    protected function emailKey(?string $email): string
    {
        $email = Str::lower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    protected function extractAddressKey(string $comment): string
    {
        if (! preg_match('/Address:\s*(.+?)(?:\s*\|\s*Source:|$)/iu', $comment, $m)) {
            return '';
        }

        $address = Str::lower(trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]));
        $address = str_replace([',', '.', ';'], ' ', $address);
        $address = trim(preg_replace('/\s+/', ' ', $address) ?? $address);

        return strlen($address) >= 8 ? $address : '';
    }

    /**
     * @return list<string>
     */
    protected function identityEmails(?string $email): array
    {
        $key = $this->emailKey($email);

        return $key !== '' ? [$key] : [];
    }

    /**
     * @return list<string>
     */
    protected function identityPhones(?string $phone): array
    {
        $key = $this->phoneKey($phone);

        return $key !== '' ? [$key] : [];
    }

    /**
     * @param  array{
     *     company_name: string,
     *     email: string,
     *     phone: string,
     *     comment: string,
     *     contact_email: string,
     *     contact_phone: string
     * }  $row
     * @param  Collection<string, DirectoryContact>  $byCompany
     * @param  Collection<string, DirectoryContact>  $byEmail
     * @param  Collection<string, DirectoryContact>  $byPhone
     * @param  Collection<string, DirectoryContact>  $byAddress
     */
    protected function findExistingContact(
        array $row,
        string $companyKey,
        $byCompany,
        $byEmail,
        $byPhone,
        $byAddress,
    ): ?DirectoryContact {
        if ($byCompany->has($companyKey)) {
            return $byCompany->get($companyKey);
        }

        foreach ([$row['email'], $row['contact_email']] as $email) {
            $key = $this->emailKey($email);
            if ($key !== '' && $byEmail->has($key)) {
                return $byEmail->get($key);
            }
        }

        foreach ([$row['phone'], $row['contact_phone']] as $phone) {
            $key = $this->phoneKey($phone);
            if ($key !== '' && $byPhone->has($key)) {
                return $byPhone->get($key);
            }
        }

        $address = $this->extractAddressKey($row['comment']);
        if ($address !== '' && $byAddress->has($address)) {
            return $byAddress->get($address);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mergeContactPayload(DirectoryContact $contact, array $payload): array
    {
        foreach (['type', 'country', 'segment', 'priority', 'website', 'email', 'phone', 'directory_contact_stage_id', 'last_contact', 'next_contact'] as $field) {
            $incoming = $payload[$field] ?? null;
            $current = $contact->{$field};

            if ($incoming === null || $incoming === '') {
                $payload[$field] = $current;
            }
        }

        // Keep existing company name if incoming is only a weaker variant.
        if (filled($contact->company_name)) {
            $payload['company_name'] = $contact->company_name;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mergePersonPayload(DirectoryContactPerson $person, array $payload): array
    {
        foreach (['phone', 'email', 'comment'] as $field) {
            if (($payload[$field] ?? null) === null || $payload[$field] === '') {
                $payload[$field] = $person->{$field};
            }
        }

        return $payload;
    }

    /**
     * @param  Collection<string, DirectoryContact>  $byCompany
     * @param  Collection<string, DirectoryContact>  $byEmail
     * @param  Collection<string, DirectoryContact>  $byPhone
     * @param  Collection<string, DirectoryContact>  $byAddress
     */
    protected function indexContact(
        DirectoryContact $contact,
        &$byCompany,
        &$byEmail,
        &$byPhone,
        &$byAddress,
        string $comment = '',
    ): void {
        $byCompany[$this->companyKey($contact->company_name)] = $contact;

        foreach ($this->identityEmails($contact->email) as $email) {
            $byEmail[$email] = $contact;
        }
        foreach ($this->identityPhones($contact->phone) as $phone) {
            $byPhone[$phone] = $contact;
        }

        $address = $this->extractAddressKey($comment);
        if ($address !== '') {
            $byAddress[$address] = $contact;
        }
    }

    protected function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_object($value)) {
            return trim((string) $value);
        }

        return trim((string) $value);
    }

    protected function normalizeWebsite(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '[object Object]') {
            return null;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;

        return strtolower(rtrim($value, '/')) ?: null;
    }

    protected function normalizePriority(string $value): ?DirectoryContactPriority
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return null;
        }

        return match (true) {
            str_contains($value, 'high') => DirectoryContactPriority::High,
            str_contains($value, 'low') => DirectoryContactPriority::Low,
            str_contains($value, 'medium'), str_contains($value, 'med') => DirectoryContactPriority::Medium,
            default => null,
        };
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (\Throwable) {
                // fall through
            }
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // Already YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text, $m)) {
            return substr($m[0], 0, 10);
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $countries
     */
    protected function resolveCountry(string $country, array $countries): ?string
    {
        $country = trim($country);
        if ($country === '' || preg_match('/^\d+$/', $country)) {
            return null;
        }

        $aliases = [
            'czechia' => 'Czech Republic',
            'uk' => 'United Kingdom',
            'usa' => 'United States',
            'us' => 'United States',
            'uae' => 'United Arab Emirates',
            'holland' => 'Netherlands',
            'be' => 'Belgium',
            'lt' => 'Lithuania',
            'se' => 'Sweden',
            'it' => 'Italy',
            'de' => 'Germany',
            'fr' => 'France',
            'pl' => 'Poland',
            'es' => 'Spain',
            'pt' => 'Portugal',
            'dk' => 'Denmark',
            'no' => 'Norway',
            'fi' => 'Finland',
            'ee' => 'Estonia',
            'lv' => 'Latvia',
            'ch' => 'Switzerland',
            'at' => 'Austria',
            'ro' => 'Romania',
            'hr' => 'Croatia',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'gr' => 'Greece',
            'ie' => 'Ireland',
            'bg' => 'Bulgaria',
        ];

        $key = Str::lower($country);
        if (isset($aliases[$key])) {
            $country = $aliases[$key];
            $key = Str::lower($country);
        }

        return $countries[$key] ?? $country;
    }

    /**
     * @param  Collection<string, DirectoryContactStage>  $stages
     */
    protected function resolveStageId(string $stageName, &$stages): ?int
    {
        $stageName = trim($stageName);
        if ($stageName === '') {
            return null;
        }

        if (Str::lower($stageName) === 'unassigned') {
            $stageName = DirectoryContactStage::defaultName();
        }

        $key = Str::lower($stageName);
        if ($stages->has($key)) {
            return $stages->get($key)->id;
        }

        $stage = DirectoryContactStage::query()->create([
            'name' => $stageName,
            'sort_order' => $key === Str::lower(DirectoryContactStage::defaultName()) ? 1 : null,
        ]);
        $stages->put($key, $stage);

        return $stage->id;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitPersonName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = explode(' ', $fullName, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
