<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\OriginCountry;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class FuturaAddressBookCustomerImporter
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(): array
    {
        $role = Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($role, &$created, &$updated, &$skipped): void {
            foreach ($this->rows() as $row) {
                $payload = $this->mapRow($row);
                $existing = $this->findExisting($payload);

                if ($existing !== null) {
                    $existing->fill(collect($payload)->except(['password', 'email'])->all());
                    // Keep existing login email unless blank/placeholder
                    if ($this->isPlaceholderEmail($existing->email) && ! $this->isPlaceholderEmail($payload['email'])) {
                        $existing->email = $payload['email'];
                    }
                    $existing->save();
                    $existing->assignRole($role);
                    $updated++;

                    continue;
                }

                if (User::withTrashed()->where('email', $payload['email'])->exists()) {
                    $skipped++;

                    continue;
                }

                $user = User::query()->create($payload);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->assignRole($role);
                $created++;
            }
        });

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function findExisting(array $payload): ?User
    {
        $query = User::query()->role(User::ROLE_CUSTOMER);

        if (filled($payload['company_vat'] ?? null)) {
            $match = (clone $query)->where('company_vat', $payload['company_vat'])->first();
            if ($match) {
                return $match;
            }
        }

        if (filled($payload['company_code'] ?? null)) {
            $match = (clone $query)->where('company_code', $payload['company_code'])->first();
            if ($match) {
                return $match;
            }
        }

        if (filled($payload['company_name'] ?? null)) {
            $match = (clone $query)->where('company_name', $payload['company_name'])->first();
            if ($match) {
                return $match;
            }
        }

        return User::query()->where('email', $payload['email'])->first();
    }

    /**
     * @param  array{
     *     company_name: string,
     *     company_code: ?string,
     *     company_vat: ?string,
     *     company_address: string,
     *     emails: string,
     *     phones: string,
     *     contact_name: ?string,
     *     contact_surname: ?string
     * }  $row
     * @return array<string, mixed>
     */
    protected function mapRow(array $row): array
    {
        $emails = $this->extractEmails($row['emails']);
        $primaryEmail = $emails[0] ?? $this->placeholderEmail($row['company_name']);
        $extraEmails = array_slice($emails, 1);

        $phone = trim($row['phones']);
        if ($extraEmails !== []) {
            $phone = trim($phone.($phone !== '' ? ' · ' : '').'Emails: '.implode('; ', $extraEmails));
        }

        $country = $this->detectCountry($row['company_address']);
        $this->ensureCountry($country);
        $isLithuania = $country === 'Lithuania';

        $name = $row['contact_name'] ?: $row['company_name'];
        $surname = $row['contact_surname'];

        return [
            'name' => Str::limit($name, 255, ''),
            'surname' => $surname,
            'email' => Str::lower($primaryEmail),
            'phone' => $phone !== '' ? $phone : null,
            'password' => Str::password(20),
            'company_name' => $row['company_name'],
            'company_code' => filled($row['company_code']) ? $row['company_code'] : null,
            'company_vat' => filled($row['company_vat']) ? $row['company_vat'] : null,
            'company_address' => $row['company_address'],
            'company_shipping_address' => $row['company_address'],
            'company_country' => $country,
            'export' => ! $isLithuania,
            'vat_rate_id' => $isLithuania
                ? VatRate::query()->where('classificator', 'PVM1')->value('id')
                : VatRate::query()->where('classificator', 'PVM4')->value('id'),
            'invoice_language' => $isLithuania
                ? InvoiceLanguage::Lithuanian->value
                : InvoiceLanguage::English->value,
        ];
    }

    /**
     * @return list<string>
     */
    protected function extractEmails(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $matches);

        $emails = [];
        foreach ($matches[0] ?? [] as $email) {
            $email = Str::lower($email);
            if (! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    protected function placeholderEmail(string $companyName): string
    {
        $slug = Str::slug($companyName);
        if ($slug === '') {
            $slug = 'customer-'.Str::lower(Str::random(8));
        }

        return $slug.'@import.futura.local';
    }

    protected function isPlaceholderEmail(?string $email): bool
    {
        return filled($email) && str_ends_with(Str::lower((string) $email), '@import.futura.local');
    }

    protected function detectCountry(string $address): string
    {
        $upper = Str::upper($address);

        $map = [
            'CZECH REPUBLIC' => 'Czech Republic',
            'FINLAND' => 'Finland',
            'ROMANIA' => 'Romania',
            'CROATIA' => 'Croatia',
            'GREECE' => 'Greece',
            'SLOVAKIA' => 'Slovakia',
            'ITALY' => 'Italy',
            'LIETUVA' => 'Lithuania',
            'LITHUANIA' => 'Lithuania',
        ];

        foreach ($map as $needle => $country) {
            if (str_contains($upper, $needle)) {
                return $country;
            }
        }

        return 'Lithuania';
    }

    protected function ensureCountry(string $name): void
    {
        OriginCountry::query()->firstOrCreate(['name' => $name]);
    }

    /**
     * @return list<array{
     *     company_name: string,
     *     company_code: ?string,
     *     company_vat: ?string,
     *     company_address: string,
     *     emails: string,
     *     phones: string,
     *     contact_name: ?string,
     *     contact_surname: ?string
     * }>
     */
    public function rows(): array
    {
        return [
            [
                'company_name' => 'TENA TEX TRADE S.R.O.',
                'company_code' => null,
                'company_vat' => null,
                'company_address' => 'PRUMYSLOVA 2547/8, 680 01 BOSKOVICE, CZECH REPUBLIC',
                'emails' => 'KOTOUC.E@TENATEX.CZ',
                'phones' => '+420 735 170 900',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'LAURITZON (OY S.W. LAURITZON & CO AB)',
                'company_code' => null,
                'company_vat' => null,
                'company_address' => 'TEERISUONKUJA 6, 00700 HELSINKI, FINLAND',
                'emails' => 'LINUS.LAURITZON@LAURITZON.FI',
                'phones' => '+358 (0)50 363 3536 -- LINUS',
                'contact_name' => 'Linus',
                'contact_surname' => 'Lauritzon',
            ],
            [
                'company_name' => 'MYSTIC EURIMPEX SRL',
                'company_code' => null,
                'company_vat' => null,
                'company_address' => '256 BASARABIA BLVD, GATE 1, BUILDING C4, DISTRICT 3, BUCHAREST 030352, ROMANIA',
                'emails' => 'COMERCIAL@MYSTICTEXTILE.RO',
                'phones' => '+40 742 208 000',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'SVETMAR D.O.O.',
                'company_code' => null,
                'company_vat' => null,
                'company_address' => 'RUDEŠKA CESTA 118, HR-10000 ZAGREB, CROATIA',
                'emails' => 'INFO@SVETMAR.HR',
                'phones' => '+385 091 442 4472',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'BIKINIS BROS S.A.',
                'company_code' => null,
                'company_vat' => 'EL094321170',
                'company_address' => 'INOI SCHIMATARI, VIOTIA, ZIP 32009, ATHENS, GREECE',
                'emails' => 'INFO@BOATS.GR',
                'phones' => '0030 2262 058573; 0030 2262 058171',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'TEXKOM S R.O.',
                'company_code' => '36 104 984',
                'company_vat' => 'SK2020406806',
                'company_address' => 'PRIEMYSELNA 2, 94901 NITRA, SLOVAKIA',
                'emails' => '',
                'phones' => '+421 905 409 126',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'TYLIGADAS BROS S.A. - VIOTAP',
                'company_code' => null,
                'company_vat' => 'EL094423021',
                'company_address' => '60 I. GAVRIEL STR. - KARAMANLIS AVE., ACHARNES 136 77, ATTIKI, GREECE',
                'emails' => 'INFO@VIOTAP.COM; ELENI@VIOTAP.COM',
                'phones' => '+30 210 24 65 510; TAKSAS +30 210 24 62 968',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'ARITEX SRL',
                'company_code' => null,
                'company_vat' => null,
                'company_address' => 'VIA DEL CRISTO 84, I-33044 MANZANO (UD), ITALY',
                'emails' => 'INFO@ARITEX.EU (BENDRAS); ORDERS@ARITEX.EU (UŽSAKYMAI); LILIANA@ARITEX.EU (SĄSKAITOS); FRANCESCO@ARITEX.EU',
                'phones' => '0432 748060',
                'contact_name' => null,
                'contact_surname' => null,
            ],
            [
                'company_name' => 'UAB "VINILAS"',
                'company_code' => '304160910',
                'company_vat' => 'LT100009844217',
                'company_address' => 'RINGUVOS G. 3D-2, LT-78415 ŠIAULIAI, LIETUVA',
                'emails' => '',
                'phones' => '',
                'contact_name' => null,
                'contact_surname' => null,
            ],
        ];
    }
}
