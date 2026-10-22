<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\ManualImports\Pages\ListManualImports;
use App\Models\ManualImport;
use App\Models\Product;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualImportDoubleClickCellEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_import_product_cell_is_double_click_editable(): void
    {
        $this->actingAsAdmin();

        $product = Product::factory()->create([
            'product_code' => 'OLD-CODE',
            'name' => '30',
        ]);
        $import = ManualImport::query()->create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'amount' => 2,
            'price' => 10.5,
            'imported_at' => now()->toDateString(),
        ]);

        $html = Livewire::test(ListManualImports::class)->html();
        $this->assertStringContainsString('data-ss-cell-field="product_code"', $html);
        $this->assertStringContainsString('data-ss-record-key="'.$import->id.'"', $html);

        Livewire::test(ListManualImports::class)
            ->callAction('doubleClickCellEdit', data: [
                'value' => 'NEW-CODE',
            ], arguments: [
                'recordKey' => (string) $import->id,
                'field' => 'product_code',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('NEW-CODE', $product->fresh()?->product_code);
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');

        $admin = User::factory()->create(['email' => 'admin-manual-import-cell@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
