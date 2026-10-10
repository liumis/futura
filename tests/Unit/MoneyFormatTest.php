<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_thousands_are_grouped_with_a_space(): void
    {
        $this->assertSame('€16 261.80', Money::format(16261.80));
        $this->assertSame('€-10 957.84', Money::format(-10957.84));
        $this->assertSame('€121.00', Money::format(121));
        $this->assertSame('USD 80.00', Money::format(80, 2, 'USD'));
        $this->assertSame('€80.00', Money::format(80, 2, 'EUR'));
    }
}
