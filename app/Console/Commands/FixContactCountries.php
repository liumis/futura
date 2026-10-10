<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\ContactCountryResolver;
use Illuminate\Console\Command;

class FixContactCountries extends Command
{
    protected $signature = 'app:fix-contact-countries
                            {--dry-run : Show changes without saving}
                            {--only-albania : Only rewrite suspicious Albania values}';

    protected $description = 'Infer and fix contact countries (clears false Albania from empty-country select default)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyAlbania = (bool) $this->option('only-albania');
        $updated = 0;
        $cleared = 0;
        $skipped = 0;

        Contact::query()
            ->orderBy('id')
            ->each(function (Contact $contact) use ($dryRun, $onlyAlbania, &$updated, &$cleared, &$skipped): void {
                $current = trim((string) ($contact->company_country ?? ''));
                $inferred = ContactCountryResolver::infer($contact);

                if ($onlyAlbania && ! ContactCountryResolver::isAlbania($current)) {
                    $skipped++;

                    return;
                }

                if ($current !== '' && ! ContactCountryResolver::isSuspiciousAlbania($current, $inferred, $contact)) {
                    $skipped++;

                    return;
                }

                $next = $inferred;
                if ($next === null && ContactCountryResolver::isAlbania($current)) {
                    $next = '';
                }

                if ($next === null) {
                    $skipped++;

                    return;
                }

                if ($next !== '' && $next === $current) {
                    $skipped++;

                    return;
                }

                $label = '#'.$contact->getKey().' '.mb_substr((string) $contact->company_name, 0, 50);
                $from = $current !== '' ? $current : '(empty)';
                $to = $next !== '' ? $next : '(cleared)';
                $this->line(($dryRun ? '[dry-run] ' : '')."{$label}: {$from} → {$to}");

                if (! $dryRun) {
                    if ($next !== '') {
                        ContactCountryResolver::ensureOriginCountry($next);
                    }
                    $contact->forceFill([
                        'company_country' => $next !== '' ? $next : null,
                    ])->save();
                }

                if ($next === '') {
                    $cleared++;
                } else {
                    $updated++;
                }
            });

        $this->newLine();
        $this->info(($dryRun ? 'Would update ' : 'Updated ').$updated
            .' · cleared '.$cleared
            .' · skipped '.$skipped);

        return self::SUCCESS;
    }
}
