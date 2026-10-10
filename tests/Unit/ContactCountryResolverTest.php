<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\ContactCountryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactCountryResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_infers_from_email_tld_and_legal_form(): void
    {
        $this->assertSame('Spain', ContactCountryResolver::infer(
            name: 'AITEX - Administration',
            email: 'info@aitex.es',
        ));

        $this->assertSame('Germany', ContactCountryResolver::infer(
            name: 'LMS Rechnung',
            email: 'sms@messe-stuttgart.de',
        ));

        $this->assertSame('Estonia', ContactCountryResolver::infer(
            name: 'Bolt Operations OÜ',
            companyId: '14532901',
        ));

        $this->assertSame('Poland', ContactCountryResolver::infer(
            name: 'Go 360 Group Jakub W',
            companyId: '5252300595',
        ));

        $this->assertSame('Lithuania', ContactCountryResolver::infer(
            name: 'Dovilė Dikšienė individuali veikla',
            companyId: 'I.V nr. 1389002',
        ));

        $this->assertSame('Spain', ContactCountryResolver::infer(
            name: 'ASOCIACIÓN DE INVESTIGACION',
            companyId: 'G03182870',
            email: 'info@aitex.es',
        ));
    }

    public function test_association_does_not_become_estonia(): void
    {
        $this->assertNull(ContactCountryResolver::infer(
            name: 'Global Travel Association',
            companyId: 'AUTO-LQWSFCGN',
        ));
    }

    public function test_suspicious_albania_is_replaced(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Bolt Operations OÜ',
            'company_id' => '14532901',
            'company_country' => 'Albania',
            'company_address' => 'Auto-created from bank statement',
        ]);

        $this->assertSame('Estonia', ContactCountryResolver::resolveForContact($contact));
        $this->assertTrue(ContactCountryResolver::isSuspiciousAlbania('Albania', 'Estonia', $contact));
    }
}
