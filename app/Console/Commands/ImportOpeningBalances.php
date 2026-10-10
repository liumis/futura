<?php

namespace App\Console\Commands;

use App\Services\OpeningBalanceImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportOpeningBalances extends Command
{
    protected $signature = 'app:import-opening-balances
                            {path? : Path to Balansas PDF}
                            {--as-of=2026-03-31 : Opening as-of date (invoice date; used as opening before 2026-04-01)}
                            {--dry-run : Parse and show totals without importing}';

    protected $description = 'Import Didžioji knyga opening balances from a Balansas / trial-balance PDF';

    public function handle(): int
    {
        $path = $this->argument('path')
            ?: 'C:/Users/liumi/Downloads/Balansas 2026 03 31.pdf';
        $asOf = (string) $this->option('as-of');

        try {
            if ($this->option('dry-run')) {
                $parser = new \Smalot\PdfParser\Parser();
                $text = $parser->parseFile($path)->getText();
                $rows = OpeningBalanceImporter::parseBalansasText($text);
                $debit = 0.0;
                $credit = 0.0;
                foreach ($rows as $row) {
                    $sides = OpeningBalanceImporter::splitAmount($row['code'], $row['amount']);
                    $debit += $sides['debit'];
                    $credit += $sides['credit'];
                    $this->line(sprintf(
                        '%s  D %s  C %s  %s',
                        $row['code'],
                        number_format($sides['debit'], 2, '.', ' '),
                        number_format($sides['credit'], 2, '.', ' '),
                        $row['name'],
                    ));
                }
                $this->info(sprintf(
                    'Rows %d · debit %s · credit %s · diff %s',
                    count($rows),
                    number_format($debit, 2, '.', ' '),
                    number_format($credit, 2, '.', ' '),
                    number_format($debit - $credit, 2, '.', ' '),
                ));

                return self::SUCCESS;
            }

            $result = OpeningBalanceImporter::importFromBalansasPdf($path, $asOf);
            $this->info(sprintf(
                'Imported opening %s as invoice #%d (%s) · %d lines · %d codes created · D %s / C %s',
                $result['as_of'],
                $result['invoice_id'],
                'GL-OB-'.str_replace('-', '', $result['as_of']),
                $result['lines'],
                $result['codes_created'],
                number_format($result['debit'], 2, '.', ' '),
                number_format($result['credit'], 2, '.', ' '),
            ));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
