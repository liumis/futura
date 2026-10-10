<?php

namespace Tests\Unit;

use App\Services\ExternalStockTitleParser;
use Tests\TestCase;

class ExternalStockTitleParserTest extends TestCase
{
    public function test_detects_leather_and_catalogs(): void
    {
        $parser = new ExternalStockTitleParser;

        $this->assertTrue($parser->isLeather('Dirbtinė oda  AGNONA 101-WHITE 30m'));
        $this->assertTrue($parser->isLeather('Dirbtine oda AGNONA 101-WHITE 30m'));
        $this->assertFalse($parser->isLeather('Katalogas Agnona'));
        $this->assertFalse($parser->isLeather('Segtuvas'));
        $this->assertFalse($parser->isLeather(null));
    }

    public function test_parses_collection_color_and_size(): void
    {
        $parser = new ExternalStockTitleParser;

        $this->assertSame([
            'collection' => 'AGNONA',
            'color_code' => '101',
            'color_name' => 'WHITE',
            'size' => '30',
        ], $parser->parseLeather('Dirbtinė oda  AGNONA 101-WHITE 30m'));

        $this->assertSame([
            'collection' => 'PALOMA',
            'color_code' => '106',
            'color_name' => 'STARFISH',
            'size' => '30',
        ], $parser->parseLeather('Dirbtinė oda  25229E 106-STARFISH 30m PALOMA'));

        $this->assertSame([
            'collection' => 'PALOMA',
            'color_code' => '103',
            'color_name' => 'PEARL',
            'size' => '30',
        ], $parser->parseLeather('Dirbtinė oda  PALOMA 103-PEARL30m'));

        $this->assertSame([
            'collection' => 'ARGANO',
            'color_code' => '172',
            'color_name' => 'MERLOT',
            'size' => '27',
        ], $parser->parseLeather('Dirbtinė oda  ARGANO 172-MERLOT 27 m'));
    }

    public function test_parses_catalog_title_as_color(): void
    {
        $parser = new ExternalStockTitleParser;

        $this->assertSame([
            'collection' => 'Agnona',
            'color_code' => 'CAT',
            'color_name' => 'Katalogas Agnona',
        ], $parser->parseCatalog('Katalogas Agnona', '/AGNONA/KATALOGAI'));

        $this->assertSame([
            'collection' => 'Argano',
            'color_code' => 'CAT',
            'color_name' => 'Katalogas Argano',
        ], $parser->parseCatalog('Katalogas Argano', '/ARGANO/KATALOGAI'));
    }

    public function test_detects_samples_warehouse_header(): void
    {
        $parser = new ExternalStockTitleParser;

        $this->assertTrue($parser->isSamplesWarehouseHeader('PAVYZDŽIŲ - Pavyzdžių sandėlis'));
        $this->assertTrue($parser->isSamplesWarehouseHeader('Pavyzdžių sandėlis'));
        $this->assertFalse($parser->isSamplesWarehouseHeader('Centrinis sandėlis'));
        $this->assertFalse($parser->isSamplesWarehouseHeader('Katalogas Agnona'));
        $this->assertFalse($parser->isSamplesWarehouseHeader(null));
    }
}
