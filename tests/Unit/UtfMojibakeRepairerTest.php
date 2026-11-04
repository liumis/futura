<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\UtfMojibakeRepairer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UtfMojibakeRepairerTest extends TestCase
{
    use RefreshDatabase;

    public function test_repairs_cp437_company_name_and_address(): void
    {
        $goodName = 'Šiaulių regiono atliekų tvarkymo centras, VšĮ';
        $goodAddress = 'Jurgeliškių k. 9, Jurgeliškių k., LT-76103 Šiaulių r.';
        $badName = iconv('CP437', 'UTF-8', $goodName);
        $badAddress = iconv('CP437', 'UTF-8', $goodAddress);
        $this->assertIsString($badName);
        $this->assertIsString($badAddress);

        $contact = Contact::query()->create([
            'company_name' => $badName,
            'company_id' => '145787276',
            'company_address' => $badAddress,
            'company_country' => 'Lithuania',
        ]);

        $result = UtfMojibakeRepairer::repairContacts(collect([$contact]), dryRun: false);

        $this->assertSame(1, $result['updated']);
        $contact->refresh();
        $this->assertSame($goodName, $contact->company_name);
        $this->assertSame($goodAddress, $contact->company_address);
    }

    public function test_dry_run_does_not_save(): void
    {
        $good = 'Technologijų era, UAB';
        $bad = iconv('CP437', 'UTF-8', $good);
        $this->assertIsString($bad);

        $contact = Contact::query()->create([
            'company_name' => $bad,
            'company_id' => '302000000',
            'company_address' => 'Vilnius',
            'company_country' => 'Lithuania',
        ]);

        $result = UtfMojibakeRepairer::repairContacts(collect([$contact]), dryRun: true);

        $this->assertSame(1, $result['updated']);
        $this->assertSame($bad, $contact->fresh()->company_name);
    }

    public function test_leaves_clean_names_alone(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Acme, UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        $result = UtfMojibakeRepairer::repairContacts(collect([$contact]), dryRun: false);

        $this->assertSame(0, $result['updated']);
        $this->assertSame('Acme, UAB', $contact->fresh()->company_name);
    }

    public function test_skips_shift_encoded_garbage_without_diacritics(): void
    {
        $contact = Contact::query()->create([
            'company_name' => '5LQNOLDYRVPRN┬íWRMDV',
            'company_id' => 'SUP-1',
            'company_address' => '—',
        ]);

        $result = UtfMojibakeRepairer::repairContacts(collect([$contact]), dryRun: false);

        $this->assertSame(0, $result['updated']);
        $this->assertSame('5LQNOLDYRVPRN┬íWRMDV', $contact->fresh()->company_name);
    }
}
