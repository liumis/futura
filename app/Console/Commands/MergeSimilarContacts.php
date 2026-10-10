<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\ContactMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MergeSimilarContacts extends Command
{
    protected $signature = 'app:merge-similar-contacts
                            {--like= : Merge contacts similar to this name (e.g. "Hans Reinke")}
                            {--dry-run : Show groups without merging}';

    protected $description = 'Merge near-duplicate contacts (same / truncated / punctuation variants)';

    public function handle(): int
    {
        $like = trim((string) $this->option('like'));
        $dryRun = (bool) $this->option('dry-run');

        if ($like !== '') {
            $group = ContactMerger::findSimilar($like);
            if (count($group) < 2) {
                $this->info('No similar duplicates found for: '.$like);
                if (count($group) === 1) {
                    $this->line('Only #'.$group[0]->getKey().' '.$group[0]->company_name);
                    $this->normalizeBankCounterparts($group[0]);
                }

                return self::SUCCESS;
            }

            return $this->mergeOneGroup($group, $dryRun);
        }

        // Auto-group all contacts by normalized name / prefix families
        $contacts = Contact::query()->orderBy('id')->get();
        $used = [];
        $groups = 0;

        foreach ($contacts as $contact) {
            $id = (int) $contact->getKey();
            if (isset($used[$id])) {
                continue;
            }

            $similar = ContactMerger::findSimilar((string) $contact->company_name);
            $ids = array_map(fn (Contact $c): int => (int) $c->getKey(), $similar);
            if (count($ids) < 2) {
                continue;
            }

            foreach ($ids as $similarId) {
                $used[$similarId] = true;
            }

            $groups++;
            $this->mergeOneGroup($similar, $dryRun);
        }

        if ($groups === 0) {
            $this->info('No similar contact groups found.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<Contact>  $group
     */
    private function mergeOneGroup(array $group, bool $dryRun): int
    {
        $labels = array_map(
            fn (Contact $c): string => '#'.$c->getKey().' '.$c->company_name,
            $group,
        );
        $this->line(($dryRun ? '[dry-run] ' : '').'Group: '.implode(' | ', $labels));

        if ($dryRun) {
            return self::SUCCESS;
        }

        try {
            $result = ContactMerger::mergeGroup(array_map(
                fn (Contact $c): int => (int) $c->getKey(),
                $group,
            ));
            $target = Contact::query()->find($result['target_id']);
            $this->info('Merged into #'.$result['target_id'].' '.$target?->company_name
                .' (invoices moved: '.$result['invoices'].')');
            if ($target !== null) {
                $this->normalizeBankCounterparts($target);
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function normalizeBankCounterparts(Contact $contact): void
    {
        $name = trim((string) $contact->company_name);
        if ($name === '') {
            return;
        }

        $norm = ContactMerger::normalizeName($name);
        if ($norm === '' || strlen($norm) < 12) {
            return;
        }

        $updated = 0;
        DB::table('bank_statement_transactions')
            ->whereNotNull('counterpart_name')
            ->where('counterpart_name', '!=', '')
            ->where('counterpart_name', '!=', $name)
            ->orderBy('id')
            ->each(function ($row) use ($name, $norm, &$updated): void {
                $rowNorm = ContactMerger::normalizeName((string) $row->counterpart_name);
                if ($rowNorm === $norm || str_starts_with($norm, $rowNorm) || str_starts_with($rowNorm, $norm)) {
                    DB::table('bank_statement_transactions')
                        ->where('id', $row->id)
                        ->update(['counterpart_name' => $name]);
                    $updated++;
                }
            });

        if ($updated > 0) {
            $this->line("Normalized {$updated} bank counterpart name(s) → {$name}");
        }
    }
}
