<?php

namespace Tests\Unit;

use App\Services\ExternalStockPdfParser;
use Tests\TestCase;

class ExternalStockPdfParserTest extends TestCase
{
    public function test_parse_text_reads_stock_and_samples_sections(): void
    {
        $text = <<<'TXT'
UAB  FUTURA TEXTILES EUROPE
Data <= 2026-11-13
Likučiai  datai 2026-11-13 (imtinai)
SumaKiekisPrekė	Vnt. kaina
 CENTR . -  Centrinis sandėlis
1 199,41//SEGTUVAS.	282 VNT 4,25Segtuvas
4 224,12/AGNONA/KATALOGA
I
945 VNT 4,47Katalogas Agnona
142,1425229E106STARFISH30	30.00 m 4,74Dirbtinė oda  25229E 106-STARFISH 30m
PALOMA
13 443,06AGNONA121BLACK30	2730.00 m 4,92Dirbtinė oda  AGNONA 121-BLACK 30m
1 884,30AGNONA102ARCTIC3
0
330.00 m 5,71Dirbtinė oda  AGNONA 102-ARCTIC 30m
61275.0000 285 987,52Viso sadėlyje CENTR.:
 PAVYZDŽIŲ  -  Pavyzdžių sandėlis
118,4525229E106STARFISH30	25.00 m 4,74Dirbtinė oda  25229E 106-STARFISH 30m
PALOMA
118,35AGNONA121BLACK30	25.00 m 4,73Dirbtinė oda  AGNONA 121-BLACK 30m
3339.5000 15 148,38Viso sadėlyje PAVYZDŽIŲ:
Viso:	64614.5000 301 135,90|__V__|
TXT;

        $rows = (new ExternalStockPdfParser)->parseText($text);

        $this->assertCount(7, $rows);
        $this->assertSame('//SEGTUVAS.', $rows[0]['code']);
        $this->assertSame(282.0, $rows[0]['quantity']);
        $this->assertSame('VNT', $rows[0]['unit']);
        $this->assertSame('stock', $rows[0]['section']);

        $this->assertSame('/AGNONA/KATALOGAI', $rows[1]['code']);
        $this->assertSame(945.0, $rows[1]['quantity']);

        $this->assertSame('25229E106STARFISH30', $rows[2]['code']);
        $this->assertSame(30.0, $rows[2]['quantity']);
        $this->assertStringContainsString('PALOMA', (string) $rows[2]['name']);

        $this->assertSame('AGNONA121BLACK30', $rows[3]['code']);
        $this->assertSame(2730.0, $rows[3]['quantity']);

        $this->assertSame('AGNONA102ARCTIC30', $rows[4]['code']);
        $this->assertSame(330.0, $rows[4]['quantity']);

        $this->assertSame('reservation', $rows[5]['section']);
        $this->assertSame('25229E106STARFISH30', $rows[5]['code']);
        $this->assertSame(25.0, $rows[5]['quantity']);
        $this->assertStringContainsString('PALOMA', (string) $rows[5]['name']);

        $this->assertSame('reservation', $rows[6]['section']);
        $this->assertSame('AGNONA121BLACK30', $rows[6]['code']);
    }
}
