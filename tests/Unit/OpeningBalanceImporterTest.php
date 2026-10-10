<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\User;
use App\Services\OpeningBalanceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBalanceImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_and_imports_balanced_opening_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        CompanySetting::instance()->update([
            'company_name' => 'Futura Textiles Europe, UAB',
            'company_id' => '306740042',
            'company_address' => 'Vilnius',
        ]);

        foreach (['2410', '4430', '3011'] as $code) {
            InvoiceCode::query()->create(['code' => $code, 'name' => $code]);
        }

        $result = OpeningBalanceImporter::importRows([
            ['code' => '2410', 'name' => 'Customers', 'amount' => 100.00],
            ['code' => '4430', 'name' => 'Suppliers', 'amount' => 60.00],
            ['code' => '3011', 'name' => 'Share capital', 'amount' => 40.00],
        ], '2026-03-31');

        $this->assertSame(3, $result['lines']);
        $this->assertEqualsWithDelta(100.0, $result['debit'], 0.001);
        $this->assertEqualsWithDelta(100.0, $result['credit'], 0.001);

        $invoice = Invoice::query()->findOrFail($result['invoice_id']);
        $this->assertSame('opening', $invoice->gl_generated_type);
        $this->assertSame('2026-03-31', $invoice->gl_generated_key);
        $this->assertTrue((bool) $invoice->is_own_account);
    }

    public function test_parse_balansas_text_recovers_wrapped_wage_line(): void
    {
        $text = <<<TXT
BALANSAS_01
2410 Pirkėjų skolų vertė 100,00
4430 Skolos tiekėjams už prekes ir paslaugas 100,00
6304 Darbuotojų darbo užmokestis ir su juo susijusios
sąnaudos
78 000,00
TXT;

        $rows = OpeningBalanceImporter::parseBalansasText($text);
        $byCode = collect($rows)->keyBy('code');
        $this->assertTrue($byCode->has('6304'));
        $this->assertEqualsWithDelta(78000.0, (float) $byCode['6304']['amount'], 0.001);
    }
}
