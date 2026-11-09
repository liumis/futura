<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Models\CustomerLevelPrice;
use App\Models\User;
use App\Services\FuturaCustomerLevelBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerLevelsC1AndKMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_c1_and_k_and_assigns_customers(): void
    {
        Role::findOrCreate(User::ROLE_CUSTOMER, 'web');

        foreach (['Agnona', 'Argano', 'Borga', 'Paloma', 'Saramo', 'Eraser', 'Ferrara'] as $name) {
            Collection::factory()->create(['name' => $name, 'price' => 5]);
        }

        $atStyle = User::factory()->create([
            'company_name' => 'A & T Style ltd',
            'company_code' => 'ATSTYLE',
            'customer_level_id' => null,
        ]);
        $atStyle->assignRole(User::ROLE_CUSTOMER);

        $vinilas = User::factory()->create([
            'company_name' => 'Vinilas, UAB',
            'company_code' => 'VINILAS',
            'customer_level_id' => null,
        ]);
        $vinilas->assignRole(User::ROLE_CUSTOMER);

        $maudis = User::factory()->create([
            'company_name' => 'UAB Maudis',
            'company_code' => null,
            'customer_level_id' => null,
        ]);
        $maudis->assignRole(User::ROLE_CUSTOMER);

        $result = app(FuturaCustomerLevelBootstrap::class)->run();

        $c1 = CustomerLevel::query()->findOrFail($result['c1_id']);
        $v = CustomerLevel::query()->findOrFail($result['v_id']);
        $k = CustomerLevel::query()->findOrFail($result['k_id']);
        $this->assertSame('C1', $c1->name);
        $this->assertSame('V', $v->name);
        $this->assertSame('K', $k->name);

        $this->assertSame(6.9, (float) CustomerLevelPrice::query()
            ->where('customer_level_id', $c1->id)
            ->whereHas('collection', fn ($q) => $q->where('name', 'Agnona'))
            ->value('price'));
        $this->assertSame(7.2, (float) CustomerLevelPrice::query()
            ->where('customer_level_id', $c1->id)
            ->whereHas('collection', fn ($q) => $q->where('name', 'Argano'))
            ->value('price'));
        $this->assertSame(6.4, (float) CustomerLevelPrice::query()
            ->where('customer_level_id', $v->id)
            ->whereHas('collection', fn ($q) => $q->where('name', 'Agnona'))
            ->value('price'));
        $this->assertSame(6.7, (float) CustomerLevelPrice::query()
            ->where('customer_level_id', $v->id)
            ->whereHas('collection', fn ($q) => $q->where('name', 'Argano'))
            ->value('price'));
        $this->assertSame(8.5, (float) CustomerLevelPrice::query()
            ->where('customer_level_id', $k->id)
            ->whereHas('collection', fn ($q) => $q->where('name', 'Agnona'))
            ->value('price'));

        $this->assertSame($c1->id, $atStyle->fresh()->customer_level_id);
        $this->assertSame($v->id, $vinilas->fresh()->customer_level_id);
        $this->assertSame($k->id, $maudis->fresh()->customer_level_id);
        $this->assertSame(1, $result['c1_assigned']);
        $this->assertSame(1, $result['v_assigned']);
        $this->assertSame(1, $result['k_assigned']);
    }
}
