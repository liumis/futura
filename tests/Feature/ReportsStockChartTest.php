<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\ReportsStockChart;
use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StockChartReportBuilder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsStockChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_stock_chart_report(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ReportsStockChart::class)
            ->assertOk()
            ->assertSee('Stock chart')
            ->assertSee('Meters')
            ->assertSee('Rolls');
    }

    public function test_builder_groups_colors_by_collection_in_meters_and_rolls(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Agnella']);
        $white = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'White',
            'color_code' => 'AG101',
            'recommended_stock' => 200,
        ]);
        $arctic = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Arctic',
            'color_code' => 'AG102',
            'recommended_stock' => 350,
        ]);

        Product::factory()->create([
            'color_id' => $white->id,
            'name' => '30',
            'current_amount' => 2,
        ]);
        Product::factory()->create([
            'color_id' => $white->id,
            'name' => '20',
            'current_amount' => 1,
        ]);
        Product::factory()->create([
            'color_id' => $arctic->id,
            'name' => '30',
            'current_amount' => 3,
        ]);

        $meters = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_METERS);
        $this->assertSame('Meters', $meters['metric_label']);
        $this->assertCount(1, $meters['collections']);
        $this->assertSame('Agnella', $meters['collections'][0]['label']);
        $this->assertSame(2, $meters['colors']);

        $byColor = collect($meters['collections'][0]['colors'])->keyBy('label');
        $this->assertSame(80.0, $byColor['White']['stock']); // (30*2) + (20*1)
        $this->assertSame(90.0, $byColor['Arctic']['stock']); // 30*3
        $this->assertSame(170.0, $meters['grand_total']);
        $this->assertSame(200.0, $byColor['White']['recommended']);
        $this->assertSame(350.0, $byColor['Arctic']['recommended']);

        $rolls = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_ROLLS);
        $byRollColor = collect($rolls['collections'][0]['colors'])->keyBy('label');
        $this->assertSame(3.0, $byRollColor['White']['stock']);
        $this->assertSame(3.0, $byRollColor['Arctic']['stock']);
        $this->assertNull($byRollColor['White']['recommended']);
        $this->assertSame(6.0, $rolls['grand_total']);
    }

    public function test_builder_includes_per_color_recommended_stock_in_meters(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Saramo']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Coffee',
            'recommended_stock' => 420,
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'current_amount' => 2,
        ]);

        $report = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_METERS);
        $this->assertSame(420.0, $report['collections'][0]['colors'][0]['recommended']);
    }

    public function test_catalog_products_are_excluded(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Ferrara']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Noir',
        ]);

        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '20',
            'current_amount' => 5,
        ]);

        Product::factory()->catalog()->create([
            'color_id' => $color->id,
            'current_amount' => 100,
        ]);

        $report = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_ROLLS);
        $this->assertSame(1, $report['colors']);
        $this->assertSame(5.0, $report['grand_total']);
    }

    public function test_livewire_switches_metric(): void
    {
        $this->actingAsAdmin();

        $collection = Collection::factory()->create(['name' => 'Palermo']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Sand',
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '10',
            'current_amount' => 4,
        ]);

        Livewire::test(ReportsStockChart::class)
            ->assertSee('Current stock by color (meters)')
            ->set('data.metric', StockChartReportBuilder::METRIC_ROLLS)
            ->assertSee('Current stock by color (rolls)');
    }

    public function test_builder_includes_low_stock_limit_from_settings(): void
    {
        $this->actingAsAdmin();

        SystemSetting::instance()->update([
            'low_stock_alert_limit' => 120.5,
        ]);

        $report = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_METERS);

        $this->assertSame(120.5, $report['low_stock_limit']);
        $this->assertSame('120.50 m', $report['low_stock_limit_label']);
    }

    public function test_admin_can_edit_low_stock_limit_from_stock_chart(): void
    {
        $this->actingAsAdmin();

        SystemSetting::instance()->update([
            'low_stock_alert_limit' => 100,
        ]);

        $collection = Collection::factory()->create(['name' => 'Roma']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Ivory',
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '10',
            'current_amount' => 2,
        ]);

        Livewire::test(ReportsStockChart::class)
            ->assertSee('100.00 m')
            ->assertSee('Click to edit')
            ->callAction('editLowStockLimit', data: [
                'low_stock_alert_limit' => 500,
            ])
            ->assertSee('500.00 m');

        $this->assertSame(500.0, SystemSetting::instance()->fresh()->lowStockAlertLimit());
    }

    private function actingAsAdmin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create(['email' => 'admin-stock-chart@example.com']);
        $admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return $admin;
    }
}
