<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\ContactMerger;
use App\Services\LithuanianCompanyNameNormalizer;
use Illuminate\Console\Command;

class NormalizeLithuanianContactNames extends Command
{
    protected $signature = 'contacts:normalize-lt-names
        {--dry-run : Show changes without saving}
        {--merge-duplicates : After rename, merge contacts that share the same normalized name}';

    protected $description = 'Normalize Lithuanian company names to "Name, UAB" (no quotes; legal form at the end)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = LithuanianCompanyNameNormalizer::normalizeContacts(null, $dryRun);

        if ($result['changes'] === []) {
            $this->info('No Lithuanian company names needed changes.');
        } else {
            foreach ($result['changes'] as $change) {
                $this->line(sprintf(
                    '#%d: %s → %s',
                    $change['id'],
                    $change['from'],
                    $change['to'],
                ));
            }
            $this->info(($dryRun ? 'Would update' : 'Updated').' '.$result['updated'].' contact(s).');
        }

        if (! $this->option('merge-duplicates')) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Skip duplicate merge in dry-run.');

            return self::SUCCESS;
        }

        $mergedGroups = 0;
        $seen = [];

        $lt = Contact::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (Contact $c): bool => LithuanianCompanyNameNormalizer::isLithuanianContact($c));

        foreach ($lt as $contact) {
            $key = mb_strtolower(trim((string) $contact->company_name));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $group = $lt->filter(
                fn (Contact $c): bool => mb_strtolower(trim((string) $c->company_name)) === $key,
            )->values();

            $seen[$key] = true;

            if ($group->count() < 2) {
                continue;
            }

            $result = ContactMerger::mergeGroup($group->modelKeys());
            $mergedGroups++;
            $this->info('Merged "'.$key.'" → #'.$result['target_id'].' (moved '.$result['invoices'].' invoice(s))');
        }

        if ($mergedGroups === 0) {
            $this->info('No duplicate Lithuanian names to merge.');
        }

        return self::SUCCESS;
    }
}
