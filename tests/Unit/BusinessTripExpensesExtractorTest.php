<?php

namespace Tests\Unit;

use App\Services\BusinessTripExpensesExtractor;
use PHPUnit\Framework\TestCase;

class BusinessTripExpensesExtractorTest extends TestCase
{
    public function test_parses_other_expenses_total_bilingual_line(): void
    {
        $text = <<<'TXT'
Iš viso dienpinigiai / Total allowance (EUR)
310.00
KITOS IŠLAIDOS / OTHER EXPENSES
1 Taxi 35.70
Kitos išlaidos iš viso / Other expenses total: 279.80
BENDRA SUMA / GRAND TOTAL (dienpinigiai + kitos išlaidos / allowance + expenses): 589.80
TXT;

        $this->assertSame(279.80, BusinessTripExpensesExtractor::parseOtherExpensesTotal($text));
    }

    public function test_does_not_treat_allowance_as_expenses(): void
    {
        $text = "Iš viso dienpinigiai / Total allowance (EUR)\n310.00\n";
        $this->assertNull(BusinessTripExpensesExtractor::parseOtherExpensesTotal($text));
    }
}
