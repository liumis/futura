<?php

namespace App\Console\Commands;

use App\Services\UtfMojibakeRepairer;
use Illuminate\Console\Command;

class RepairUtfMojibake extends Command
{
    protected $signature = 'contacts:repair-utf
        {--dry-run : Show repairs without saving}';

    protected $description = 'Repair CP437/OEM mojibake in finance/CRM contacts and CRM comments (d─ùl → dėl)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $finance = UtfMojibakeRepairer::repairContacts(null, $dryRun);
        $directory = UtfMojibakeRepairer::repairDirectoryContacts(null, $dryRun);

        $changes = array_merge($finance['changes'], $directory['changes']);

        if ($changes === []) {
            $this->info('No corrupted UTF contact fields found.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->line(sprintf(
                '#%d.%s: %s → %s',
                $change['id'],
                $change['field'],
                $change['from'],
                $change['to'],
            ));
        }

        $this->info(($dryRun ? 'Would update' : 'Updated').' '
            .($finance['updated'] + $directory['updated']).' record(s), '
            .count($changes).' field(s).');

        return self::SUCCESS;
    }
}
