<?php

namespace Tests\Feature;

use App\Services\InvoiceAiMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceAiMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_mapper_fills_unknown_layout(): void
    {
        config([
            'services.invoice_ai.key' => 'test-ai-key',
            'services.invoice_ai.base_url' => 'https://api.openai.com/v1',
            'services.invoice_ai.model' => 'gpt-4o-mini',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'provider_company_name' => 'Šiaulių prekybos, pramonės ir amatų rūmai',
                            'provider_company_id' => '110067596',
                            'provider_company_vat' => 'LT100675917',
                            'invoice_number' => 'NFM-0000253',
                            'invoice_date' => '2026-08-27',
                            'sum_without_vat' => 50,
                            'vat' => 0,
                            'sum_inc_vat' => 50,
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $mapped = app(InvoiceAiMapper::class)->map('SĄSKAITA NFM 0000253 Suma be PVM 50,00');

        $this->assertSame('Šiaulių prekybos, pramonės ir amatų rūmai', $mapped['provider_company_name'] ?? null);
        $this->assertSame('NFM-0000253', $mapped['invoice_number'] ?? null);
        $this->assertSame('2026-08-27', $mapped['invoice_date'] ?? null);
        $this->assertSame(50.0, $mapped['sum_inc_vat'] ?? null);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'chat/completions'));
    }

    public function test_ai_mapper_is_disabled_without_key(): void
    {
        config(['services.invoice_ai.key' => null]);

        $this->assertFalse(app(InvoiceAiMapper::class)->isEnabled());
        $this->assertSame([], app(InvoiceAiMapper::class)->map('SĄSKAITA NFM 0000253'));
    }
}
