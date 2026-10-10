<?php

namespace Tests\Unit;

use App\Services\BankStatements\BankStatementCounterpartCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankStatementCounterpartCleanerTest extends TestCase
{
    #[DataProvider('examples')]
    public function test_cleans_counterpart_names(string $input, string $expected): void
    {
        $this->assertSame($expected, BankStatementCounterpartCleaner::clean($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function examples(): array
    {
        return [
            'card anthropic' => [
                'Anthropic, PBC 30/07/2026 10:05 kortelė...133381 ANTHROPIC/SAN FRANCISCO/USA #065118',
                'Anthropic, PBC',
            ],
            'card anthropic claude' => [
                'Anthropic, PBC 22/07/2026 09:54 kortelė...133381 ANTHROPIC* CLAUDE SUB/SAN FRANCISCO/USA #264456',
                'Anthropic, PBC',
            ],
            'shipping company' => [
                'UAB "Technologijų era" R687004441 (P0065214) Užs: 2685356-9587067 (https://www.siuskpigiau.lt) apmokėjimas',
                'Technologijų era, UAB',
            ],
            'quoted org with code' => [
                'Gamintojų ir importuotojų asociacija "GAMTOS ATEITIS" GIA051035',
                'Gamintojų ir importuotojų asociacija "GAMTOS ATEITIS"',
            ],
            'card fee' => [
                'Kortelės..**3381 Kortelės naudojimo mokestis (2026/07)',
                'Kortelės naudojimo mokestis',
            ],
            'linkedin card' => [
                'LINKEDIN IRELAND UNLIMITED COMPANY 13/07/2026 13:41 kortelė...133381 LinkedIn P3051495571/LINKEDIN.COM/IRL #352823',
                'LINKEDIN IRELAND UNLIMITED COMPANY',
            ],
            'clean name unchanged' => [
                'Customer UAB',
                'Customer, UAB',
            ],
            'ariel strips remittance from counterpart' => [
                'Ariel italia Srls SERIJA FTE NR.: 0112',
                'Ariel italia Srls',
            ],
            'autoleder strips fte from counterpart' => [
                'Autoleder KFT FTE0089',
                'Autoleder KFT',
            ],
            'vinilas strips payment text from counterpart' => [
                'UAB "Vinilas" Payment for goods after cover FTE0092, FTE0090',
                'Vinilas, UAB',
            ],
            'diversified strips trailing invoice numbers' => [
                'Diversified UK & Europe INV2018888143 ,INV201888144',
                'Diversified UK & Europe',
            ],
            'diversified strips single trailing invoice' => [
                'Diversified UK & Europe INV201894420',
                'Diversified UK & Europe',
            ],
            'anthropic short merchant' => [
                'ANTHROPIC',
                'Anthropic, PBC',
            ],
            'anthropic claude sub merchant' => [
                'ANTHROPIC* CLAUDE SUB',
                'Anthropic, PBC',
            ],
            'anthropic already canonical' => [
                'Anthropic, PBC',
                'Anthropic, PBC',
            ],
            'finvalda strips atsiskaitymas from counterpart' => [
                'UAB" Finansų valdymo sistemos"  Atsiskaitymas',
                'Finansų valdymo sistemos, UAB',
            ],
        ];
    }

    public function test_extracts_trailing_atsiskaitymas_remittance(): void
    {
        $this->assertSame(
            'Atsiskaitymas',
            BankStatementCounterpartCleaner::extractTrailingRemittance(
                'UAB" Finansų valdymo sistemos"  Atsiskaitymas',
            ),
        );
    }

    public function test_extracts_vmi_and_sodra_tax_codes(): void
    {
        $this->assertSame(
            'PVM',
            BankStatementCounterpartCleaner::extractTrailingRemittance(
                'VALSTYBINĖ MOKESČIŲ INSPEKCIJA  PVM',
            ),
        );
        $this->assertSame(
            'GPM',
            BankStatementCounterpartCleaner::extractTrailingRemittance(
                'VALSTYBINĖ MOKESČIŲ INSPEKCIJA  GPM',
            ),
        );
        $this->assertSame(
            '1311',
            BankStatementCounterpartCleaner::extractTrailingRemittance(
                'VALSTYBINĖ MOKESČIŲ INSPEKCIJA  1311',
            ),
        );
        $this->assertSame(
            '252',
            BankStatementCounterpartCleaner::extractTrailingRemittance('SODRA  252'),
        );
        $this->assertNull(
            BankStatementCounterpartCleaner::extractTrailingRemittance(
                'Landesmesse Stuttgart GmbH & Co. KG  10046874',
            ),
        );
    }
}
