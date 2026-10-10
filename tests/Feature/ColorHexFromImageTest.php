<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Color;
use App\Models\Product;
use App\Services\ColorHexFromImage;
use App\Services\StockChartReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ColorHexFromImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_hex_from_solid_jpeg_swatch(): void
    {
        Storage::fake('public');
        config(['filesystems.default' => 'public']);

        $path = 'colors/test/red.jpg';
        $image = imagecreatetruecolor(40, 40);
        $red = imagecolorallocate($image, 200, 40, 40);
        imagefilledrectangle($image, 0, 0, 39, 39, $red);

        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);

        $color = Color::factory()->create([
            'image' => $path,
            'hex' => null,
        ]);

        $hex = app(ColorHexFromImage::class)->extractFromColor($color);

        $this->assertNotNull($hex);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $hex);
        // Allow small JPEG compression drift.
        $this->assertEqualsWithDelta(200, hexdec(substr($hex, 1, 2)), 25);
        $this->assertEqualsWithDelta(40, hexdec(substr($hex, 3, 2)), 25);
        $this->assertEqualsWithDelta(40, hexdec(substr($hex, 5, 2)), 25);
    }

    public function test_fill_all_sets_missing_hex_only(): void
    {
        Storage::fake('public');
        config(['filesystems.default' => 'public']);

        $path = 'colors/test/blue.jpg';
        $image = imagecreatetruecolor(20, 20);
        $blue = imagecolorallocate($image, 30, 80, 200);
        imagefilledrectangle($image, 0, 0, 19, 19, $blue);
        ob_start();
        imagejpeg($image, null, 90);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put($path, $bytes);

        $empty = Color::factory()->create(['image' => $path, 'hex' => null]);
        $kept = Color::factory()->create(['image' => $path, 'hex' => '#112233']);

        $result = app(ColorHexFromImage::class)->fillAll(force: false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotNull($empty->fresh()->hex);
        $this->assertSame('#112233', $kept->fresh()->hex);
    }

    public function test_stock_chart_builder_includes_hex(): void
    {
        $collection = Collection::factory()->create(['name' => 'Agnella']);
        $color = Color::factory()->create([
            'collection_id' => $collection->id,
            'color_name' => 'Black',
            'hex' => '#1a1a1a',
        ]);
        Product::factory()->create([
            'color_id' => $color->id,
            'name' => '30',
            'current_amount' => 2,
        ]);

        $report = StockChartReportBuilder::build(StockChartReportBuilder::METRIC_METERS);

        $this->assertSame('#1A1A1A', $report['collections'][0]['colors'][0]['hex']);
    }
}
