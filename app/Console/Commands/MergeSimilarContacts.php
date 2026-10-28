<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\ContactMerger;
use Illuminate\Console\Command;
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
                    $updated = ContactMerger::normalizeBankCounterparts($group[0]);
                    if ($updated > 0) {
                        $this->line("Normalized {$updated} bank counterpart name(s) → {$group[0]->company_name}");
                    }
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
                $updated = ContactMerger::normalizeBankCounterparts($target);
                if ($updated > 0) {
                    $this->line("Normalized {$updated} bank counterpart name(s) → {$target->company_name}");
                }
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
