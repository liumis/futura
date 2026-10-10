<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\RekvizitaiLtClient;
use Illuminate\Console\Command;

class SyncContactsFromRekvizitai extends Command
{
    protected $signature = 'contacts:sync-rekvizitai
                            {--dry-run : Show updates without writing}
                            {--id=* : Limit to specific contact ids}
                            {--only-missing : Only fill empty fields (do not overwrite existing)}';

    protected $description = 'Update Lithuanian contacts from public rekvizitai.vz.lt company pages';

    public function handle(RekvizitaiLtClient $client): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = (bool) $this->option('only-missing');
        $ids = collect($this->option('id'))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $contacts = Contact::query()
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('company_name')
            ->get();

        $targets = $contacts->filter(fn (Contact $contact): bool => $this->looksLithuanian($contact))->values();

        $this->info('Lithuanian contacts to check: '.$targets->count());

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($targets as $contact) {
            $expectedId = $this->usableCompanyId($contact);
            $data = $client->lookupByName((string) $contact->company_name, $expectedId);

            if ($data === null) {
                $this->warn("#{$contact->id} {$contact->company_name}: not found on rekvizitai");
                $failed++;

                continue;
            }

            $changes = $this->buildChanges($contact, $data, $onlyMissing);
            if ($changes === []) {
                $this->line("#{$contact->id} {$contact->company_name}: already up to date ({$data['slug']})");
                $skipped++;

                continue;
            }

            $this->line("#{$contact->id} {$contact->company_name} ← {$data['url']}");
            foreach ($changes as $field => $value) {
                $old = (string) ($contact->{$field} ?? '');
                $this->line("  {$field}: ".($old !== '' ? $old : '—').' → '.$value);
            }

            if (! $dryRun) {
                $contact->forceFill($changes)->save();
            }

            $updated++;
            usleep(350000);
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '')."Updated: {$updated}; unchanged: {$skipped}; not found: {$failed}");

        return self::SUCCESS;
    }

    private function looksLithuanian(Contact $contact): bool
    {
        $name = mb_strtolower((string) $contact->company_name);
        $vat = mb_strtoupper(preg_replace('/\s+/', '', (string) $contact->company_vat) ?? '');
        $country = mb_strtolower((string) ($contact->company_country ?? ''));
        $id = preg_replace('/\D+/', '', (string) $contact->company_id) ?? '';

        // Skip obvious OCR / foreign junk.
        if (preg_match('/kaina|suma|eil\.|fraser|bolt operations|go 360|karmman|diversified|oü\b|gmbh|ltd\b|limited/iu', $name) === 1) {
            return false;
        }

        $rawId = (string) $contact->company_id;
        if (preg_match('/inv|number:|auto-/iu', $rawId) === 1) {
            // Still allow if name clearly Lithuanian legal form.
            return str_contains($name, 'uab')
                || preg_match('/\bmb\b/u', $name) === 1
                || preg_match('/\bab\b/u', $name) === 1
                || str_contains($name, 'všį')
                || str_contains($name, 'vsi')
                || str_contains($name, 'idv');
        }

        if (in_array($country, ['lt', 'lithuania', 'lietuva', 'lietuvos'], true)) {
            return true;
        }
        if (str_starts_with($vat, 'LT')) {
            return true;
        }

        $hasLtForm = str_contains($name, 'uab')
            || preg_match('/\bmb\b/u', $name) === 1
            || preg_match('/\bab\b/u', $name) === 1
            || str_contains($name, 'všį')
            || str_contains($name, 'vsi')
            || str_contains($name, 'idv')
            || str_contains($name, 'lietuva');

        // 9-digit company codes alone are weak (OCR noise) — require LT legal form too.
        if (strlen($id) === 9 && $hasLtForm) {
            return true;
        }

        return $hasLtForm;
    }

    private function usableCompanyId(Contact $contact): ?string
    {
        $id = preg_replace('/\D+/', '', (string) $contact->company_id) ?? '';
        if (strlen($id) !== 9) {
            return null;
        }

        // Futura's own code was OCR-copied onto many suppliers — ignore as expected match key
        // unless the contact is Futura itself.
        if ($id === '306740042' && ! str_contains(mb_strtolower((string) $contact->company_name), 'futura')) {
            return null;
        }

        return $id;
    }

    /**
     * @param  array{
     *     name: ?string,
     *     company_id: ?string,
     *     company_vat: ?string,
     *     company_address: ?string,
     *     company_phone: ?string,
     *     company_email: ?string,
     *     company_country: string
     * }  $data
     * @return array<string, string>
     */
    private function buildChanges(Contact $contact, array $data, bool $onlyMissing): array
    {
        $map = [
            'company_id' => $data['company_id'] ?? null,
            'company_vat' => $data['company_vat'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'company_phone' => $data['company_phone'] ?? null,
            'company_email' => $data['company_email'] ?? null,
            'company_country' => $data['company_country'] ?? 'Lithuania',
        ];

        // Prefer official rekvizitai name only when current name is clearly dirty / bank-import style.
        $currentName = trim((string) $contact->company_name);
        $officialName = trim((string) ($data['name'] ?? ''));
        if ($officialName !== '' && $this->shouldReplaceName($currentName, $officialName)) {
            $map['company_name'] = $officialName;
        }

        $changes = [];
        foreach ($map as $field => $value) {
            if (! filled($value)) {
                continue;
            }

            $value = trim((string) $value);
            $current = trim((string) ($contact->{$field} ?? ''));

            if ($onlyMissing && $current !== '' && ! $this->isPlaceholder($current, $field)) {
                continue;
            }

            if ($this->isPlaceholder($current, $field) || $current !== $value) {
                // Don't overwrite a good existing email/phone with empty; already guarded by filled().
                if (in_array($field, ['company_email', 'company_phone'], true)
                    && $current !== ''
                    && ! $this->isPlaceholder($current, $field)
                    && $onlyMissing
                ) {
                    continue;
                }

                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    private function shouldReplaceName(string $current, string $official): bool
    {
        if ($current === $official) {
            return false;
        }

        // Keep curated names like "IT Specai, UAB" if they already match closely.
        $a = mb_strtolower($current);
        $b = mb_strtolower($official);
        if ($a === $b) {
            return false;
        }

        return str_contains($current, 'IKEA')
            || str_starts_with($current, 'UAB FUTURA')
            || str_contains($current, '“')
            || str_contains($current, '"REALM');
    }

    private function isPlaceholder(string $value, string $field): bool
    {
        $v = mb_strtolower(trim($value));
        if ($v === '' || $v === '—') {
            return true;
        }

        if (str_starts_with($v, 'auto-created')) {
            return true;
        }

        if ($field === 'company_id' && str_starts_with($v, 'auto-')) {
            return true;
        }

        if ($field === 'company_address' && str_contains($v, 'auto-created')) {
            return true;
        }

        return false;
    }
}
