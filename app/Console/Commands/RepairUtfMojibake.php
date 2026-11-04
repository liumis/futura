<?php

namespace App\Console\Commands;

use App\Services\UtfMojibakeRepairer;
use Illuminate\Console\Command;

class RepairUtfMojibake extends Command
{
    protected $signature = 'contacts:repair-utf
        {--dry-run : Show repairs without saving}';

    protected $description = 'Repair CP437/OEM mojibake in contact names and addresses (┼áiauli┼│ → Šiaulių)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = UtfMojibakeRepairer::repairContacts(null, $dryRun);

        if ($result['changes'] === []) {
            $this->info('No corrupted UTF contact fields found.');

            return self::SUCCESS;
        }

        foreach ($result['changes'] as $change) {
            $this->line(sprintf(
                '#%d.%s: %s → %s',
                $change['id'],
                $change['field'],
                $change['from'],
                $change['to'],
            ));
        }

        $this->info(($dryRun ? 'Would update' : 'Updated').' '.$result['updated'].' contact(s), '
            .count($result['changes']).' field(s).');

        return self::SUCCESS;
    }
}
