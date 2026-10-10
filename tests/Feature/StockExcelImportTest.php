<?php

namespace Tests\Feature;

use App\Enums\ActivityLogEvent;
use App\Filament\Admin\Pages\Stock;
use App\Models\ActivityLog;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_stock_page_with_excel_import(): void
    {
        $this->actingAsAdmin();

        Livewire::test(Stock::class)
            ->assertOk()
            ->assertSee('Update from Excel')
            ->assertSee('Stock items')
            ->assertSee('Stock (m)')
            ->assertSee('Write off')
            ->assertSee('Type')
            ->assertSee('Collection');
    }

    public function test_stock_page_shows_item_count_and_meters(): void
    {
        $this->actingAsAdmin();

        $leather = Product::factory()->create([
            'product_code' => 'LEATHER-METERS',
            'name' => '20',
            'current_amount' => 3,
        ]);
        $catalog = Product::factory()->catalog()->create([
            'product_code' => 'CATALOG-PIECE',
            'current_amount' => 5,
        ]);

        Livewire::test(Stock::class)
            ->assertOk()
            ->assertSee('Stock items')
            ->assertSee('Stock (m)')
            ->assertCanSeeTableRecords([$leather, $catalog])
            ->assertTableColumnStateSet('current_amount', 3, $leather)
            ->assertTableColumnStateSet('stock_meters', 60.0, $leather)
            ->assertTableColumnStateSet('current_amount', 5, $catalog)
            ->assertTableColumnStateSet('stock_meters', null, $catalog);
    }

    public function test_stock_page_filters_by_product_type(): void
    {
        $this->actingAsAdmin();

        $leather = Product::factory()->create(['product_code' => 'LEATHER-1']);
        $catalog = Product::factory()->catalog()->create(['product_code' => 'CATALOG-1']);

        Livewire::test(Stock::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$leather, $catalog])
            ->filterTable('product_type_id', ProductType::catalogId())
            ->assertCanSeeTableRecords([$catalog])
            ->assertCanNotSeeTableRecords([$leather]);
    }

    public function test_stock_page_imports_excel_and_writes_activity_log(): void
    {
        $admin = $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnona']);
        $color = Color::factory()->create([
            'collection_id' => $collection->getKey(),
            'color_code' => '101',
            'color_name' => 'WHITE',
        ]);
        $product = Product::factory()->create([
            'color_id' => $color->getKey(),
            'name' => '30',
            'product_code' => 'AG101-30',
            'current_amount' => 2,
        ]);

        $absolute = $this->writeSpreadsheet([
            ['A1' => 'Prekė', 'G1' => 'Kiekis'],
            ['A2' => 'AGNONA101WHITE30', 'G2' => 180, 'L2' => 'm'],
        ]);
        $relative = 'stock-excel-imports/balances-'.uniqid('', true).'.xlsx';
        Storage::disk('local')->put($relative, (string) file_get_contents($absolute));
        @unlink($absolute);

        Livewire::test(Stock::class)
            ->call('importStockFromExcel', $relative)
            ->assertNotified('Stock updated from Excel');

        $this->assertSame(6, (int) $product->fresh()->current_amount);
        $this->assertFalse(Storage::disk('local')->exists($relative));
        $this->assertTrue(
            ActivityLog::query()
                ->where('event_key', ActivityLogEvent::StockImportedFromExcel->value)
                ->where('user_id', $admin->getKey())
                ->exists(),
        );
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-stock-excel@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * @param  list<array<string, mixed>>  $cellsByRow
     */
    private function writeSpreadsheet(array $cellsByRow): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($cellsByRow as $cells) {
            foreach ($cells as $coordinate => $value) {
                $sheet->setCellValue($coordinate, $value);
            }
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-page-import-'.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
