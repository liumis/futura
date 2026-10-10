<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FuturaAddressBookCustomerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FuturaAddressBookCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_address_book_companies_as_customers(): void
    {
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');

        $result = app(FuturaAddressBookCustomerImporter::class)->import();

        $this->assertSame(9, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(9, User::role(User::ROLE_CUSTOMER)->count());

        $aritex = User::query()->where('company_name', 'ARITEX SRL')->first();
        $this->assertNotNull($aritex);
        $this->assertSame('info@aritex.eu', $aritex->email);
        $this->assertSame('Italy', $aritex->company_country);
        $this->assertTrue((bool) $aritex->export);
        $this->assertStringContainsString('orders@aritex.eu', (string) $aritex->phone);

        $vinilas = User::query()->where('company_code', '304160910')->first();
        $this->assertNotNull($vinilas);
        $this->assertSame('LT100009844217', $vinilas->company_vat);
        $this->assertSame('Lithuania', $vinilas->company_country);
        $this->assertFalse((bool) $vinilas->export);
        $this->assertStringEndsWith('@import.futura.local', $vinilas->email);

        $again = app(FuturaAddressBookCustomerImporter::class)->import();
        $this->assertSame(0, $again['created']);
        $this->assertSame(9, $again['updated']);
        $this->assertSame(9, User::role(User::ROLE_CUSTOMER)->count());
    }
}
