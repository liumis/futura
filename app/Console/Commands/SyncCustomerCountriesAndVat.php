<?php

namespace App\Console\Commands;

use App\Models\OriginCountry;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncCustomerCountriesAndVat extends Command
{
    protected $signature = 'app:sync-customer-countries-and-vat
                            {--dry-run : Show changes without saving}';

    protected $description = 'Fill missing customer countries and set PVM1 (LT) / PVM4 (export)';

    public function handle(): int
    {
        $pvm1 = VatRate::query()->where('classificator', 'PVM1')->value('id');
        $pvm4 = VatRate::query()->where('classificator', 'PVM4')->value('id');

        if ($pvm1 === null || $pvm4 === null) {
            $this->error('PVM1 and/or PVM4 vat rates are missing.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;

        User::query()
            ->role(User::ROLE_CUSTOMER)
            ->orderBy('id')
            ->each(function (User $user) use ($pvm1, $pvm4, $dryRun, &$updated): void {
                $country = $this->resolveCountry($user);
                $isLithuania = $this->isLithuania($country);
                $vatRateId = $isLithuania ? (int) $pvm1 : (int) $pvm4;
                $export = ! $isLithuania;

                $changes = [];
                if (trim((string) $user->company_country) !== $country) {
                    $changes['company_country'] = $country;
                }
                if ((bool) $user->export !== $export) {
                    $changes['export'] = $export;
                }
                if ((int) ($user->vat_rate_id ?? 0) !== $vatRateId) {
                    $changes['vat_rate_id'] = $vatRateId;
                }

                if ($changes === []) {
                    return;
                }

                $label = '#'.$user->getKey().' '.($user->company_name ?: $user->fullName() ?: $user->email);
                $this->line(($dryRun ? '[dry-run] ' : '').$label.' → '
                    .$country
                    .' · '.($export ? 'export' : 'domestic')
                    .' · '.($isLithuania ? 'PVM1' : 'PVM4'));

                if (! $dryRun) {
                    OriginCountry::query()->firstOrCreate(['name' => $country]);
                    $user->forceFill($changes)->save();
                }

                $updated++;
            });

        $this->newLine();
        $this->info(($dryRun ? 'Would update ' : 'Updated ').$updated.' customer(s).');

        return self::SUCCESS;
    }

    private function resolveCountry(User $user): string
    {
        $existing = trim((string) ($user->company_country ?? ''));
        if ($existing !== '') {
            return $this->normalizeCountryName($existing);
        }

        $hay = Str::upper(trim(implode(' ', array_filter([
            (string) ($user->company_address ?? ''),
            (string) ($user->company_shipping_address ?? ''),
            (string) ($user->company_name ?? ''),
            (string) ($user->company_code ?? ''),
            (string) ($user->company_vat ?? ''),
        ]))));

        $inferred = $this->detectCountryFromText($hay);
        if ($inferred !== null) {
            return $inferred;
        }

        // Legal-form / name heuristics for customers imported without address
        $name = Str::upper((string) ($user->company_name ?? ''));
        $map = [
            ' KFT' => 'Hungary',
            ' LDA' => 'Portugal',
            'TEXTEIS' => 'Portugal',
            'ITALIA' => 'Italy',
            ' UAB' => 'Lithuania',
            ' UAB"' => 'Lithuania',
            'LIETUVA' => 'Lithuania',
            'LT-' => 'Lithuania',
        ];
        foreach ($map as $needle => $country) {
            if (str_contains($name, $needle)) {
                return $country;
            }
        }

        if (str_ends_with(Str::lower($name), ' ltd') || str_contains($name, ' LTD')) {
            return 'United Kingdom';
        }

        // Default domestic when nothing else is known (local test / incomplete records)
        return 'Lithuania';
    }

    private function detectCountryFromText(string $upper): ?string
    {
        if ($upper === '') {
            return null;
        }

        $map = [
            'CZECH REPUBLIC' => 'Czech Republic',
            'UNITED KINGDOM' => 'United Kingdom',
            'HUNGARY' => 'Hungary',
            'PORTUGAL' => 'Portugal',
            'FINLAND' => 'Finland',
            'ROMANIA' => 'Romania',
            'CROATIA' => 'Croatia',
            'GREECE' => 'Greece',
            'SLOVAKIA' => 'Slovakia',
            'ITALY' => 'Italy',
            'ITALIA' => 'Italy',
            'LIETUVA' => 'Lithuania',
            'LITHUANIA' => 'Lithuania',
            ' LT-' => 'Lithuania',
            'LT-' => 'Lithuania',
            ' HR-' => 'Croatia',
            ' I-' => 'Italy',
        ];

        foreach ($map as $needle => $country) {
            if (str_contains($upper, $needle)) {
                return $country;
            }
        }

        return null;
    }

    private function normalizeCountryName(string $country): string
    {
        $trimmed = trim($country);
        $aliases = [
            'LT' => 'Lithuania',
            'Lietuva' => 'Lithuania',
            'LIETUVA' => 'Lithuania',
            'Italia' => 'Italy',
            'IT' => 'Italy',
            'UK' => 'United Kingdom',
            'GB' => 'United Kingdom',
            'CZ' => 'Czech Republic',
            'SK' => 'Slovakia',
            'FI' => 'Finland',
            'RO' => 'Romania',
            'HR' => 'Croatia',
            'GR' => 'Greece',
            'HU' => 'Hungary',
            'PT' => 'Portugal',
        ];

        return $aliases[$trimmed] ?? $trimmed;
    }

    private function isLithuania(string $country): bool
    {
        $normalized = Str::lower($this->normalizeCountryName($country));

        return in_array($normalized, ['lithuania', 'lietuva', 'lt'], true);
    }
}
