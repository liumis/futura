<?php

namespace Tests\Unit;

use App\Services\BankStatements\BankStatementDescriptionCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BankStatementDescriptionCleanerTest extends TestCase
{
    #[DataProvider('examples')]
    public function test_cleans_descriptions(string $input, ?string $expected, ?string $counterpart = null): void
    {
        $this->assertSame($expected, BankStatementDescriptionCleaner::clean($input, $counterpart));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2?: ?string}>
     */
    public static function examples(): array
    {
        return [
            'ism underscore drops company' => [
                'BANKAS_IŠM · ITC002322 · UAB "IT Specai" ITC002322',
                'ITC002322',
            ],
            'edv drops company' => [
                'EDV1096881 · UAB "Eurobiuras"',
                'EDV1096881',
            ],
            'ipl underscore keeps transfer note' => [
                'BANKAS_ĮPL · 341 · Tarp savo sąskaitų (Between accounts)',
                '341 · Tarp savo sąskaitų (Between accounts)',
            ],
            'ism slash keeps transfer note' => [
                'BANKAS/IŠM · 341 · Tarp savo sąskaitų (Between accounts)',
                '341 · Tarp savo sąskaitų (Between accounts)',
            ],
            'ipl ascii slash drops company' => [
                'BANKAS/IPL · BNK26196HBCGKLDC · Autoleder KFT -',
                'BNK26196HBCGKLDC',
            ],
            'space separator drops merchant' => [
                'BANKAS IŠM · CLR13429423 · Anthropic, PBC 30/07/2026 10:05 kortelė...',
                'CLR13429423',
            ],
            'leftover direction token drops merchant' => [
                'IŠM · CLR13429423 · Anthropic, PBC',
                'CLR13429423',
            ],
            'card remittance drops merchant' => [
                'CLR13429423 · Anthropic, PBC 30/07/2026 10:05 kortelė...133381 ANTHROPIC/SAN FRANCISCO/USA #065118',
                'CLR13429423',
            ],
            'shipping payment drops company' => [
                'RO2381034756L01 · UAB "Technologijų era" R687004441 (P0065214) Užs: 2685356-9587067 (https://www.siuskpigiau.lt) apmokėjimas',
                'RO2381034756L01 · apmokėjimas',
            ],
            'association drops name' => [
                'GIA051035 · Gamintojų ir importuotojų asociacija "GAMTOS ATEITIS" GIA051035',
                'GIA051035',
            ],
            'payment number only with atsiskaitymas' => [
                '271 · UAB "DSV Road" Atsiskaitymas',
                '271 · Atsiskaitymas',
            ],
            'payment number only realm' => [
                '270 · UAB "Realm Juridical" Atsiskaitymas',
                '270 · Atsiskaitymas',
            ],
            'payment number only it specai' => [
                '269 · UAB "IT Specai" Atsiskaitymas',
                '269 · Atsiskaitymas',
            ],
            'short doc keeps counterpart atsiskaitymas' => [
                '280 · Atsiskaitymas',
                '280 · Atsiskaitymas',
            ],
            'overpayment return keeps permoka' => [
                '52160594 · Futura Textiles Europe permoka',
                '52160594 · permoka',
                'UAB "Skubios siuntos"',
            ],
            'vmi pvm tax code kept' => [
                '286 · PVM',
                '286 · PVM',
                'VALSTYBINĖ MOKESČIŲ INSPEKCIJA PVM',
            ],
            'vmi gpm tax code kept' => [
                '267 · GPM',
                '267 · GPM',
                'VALSTYBINĖ MOKESČIŲ INSPEKCIJA GPM',
            ],
            'payment keeps invoice text without company' => [
                '4786 · UAB "Vinilas" Payment for goods after cover FTE0092',
                '4786 · Payment for goods after cover FTE0092',
            ],
            'ariel strips company keeps series' => [
                'BANKAS/IPL · 1101261960186246 · Ariel italia Srls SERIJA FTE NR.: 0112',
                '1101261960186246 · SERIJA FTE NR.: 0112',
            ],
            'ariel remittance only strips company' => [
                'Ariel italia Srls SERIJA FTE NR.: 0112',
                'SERIJA FTE NR.: 0112',
            ],
            'already clean company dropped' => [
                'ITC002322 · UAB "IT Specai"',
                'ITC002322',
            ],
            'person name dropped' => [
                'CLR13175535 · Deividas Arman',
                'CLR13175535',
            ],
            'bank fee note kept' => [
                'RO2347001306L03 · Mokestis RMO26897983 (bank fee)',
                'RO2347001306L03 · Mokestis RMO26897983 (bank fee)',
            ],
            'bank services note kept' => [
                '- · Banko paslaugos',
                '- · Banko paslaugos',
            ],
            'dash with company only' => [
                '- · UAB "FELIT"',
                null,
            ],
            'autoleder keeps fte only' => [
                'BNK26132MMLCH0MH · Autoleder KFT FTE0089',
                'BNK26132MMLCH0MH · FTE0089',
            ],
            'svetmar keeps invoice nr' => [
                '2626152000/00001 · Svetmar d.o.o. INVOICE NR.:0083',
                '2626152000/00001 · INVOICE NR.:0083',
            ],
            'itc nr without company' => [
                '303 · UAB "IT Specai" ITC Nr.002240',
                '303 · ITC Nr.002240',
            ],
            'transfer note kept with matching counterpart' => [
                '341 · Tarp savo sąskaitų (Between accounts)',
                '341 · Tarp savo sąskaitų (Between accounts)',
                'Tarp savo sąskaitų (Between accounts)',
            ],
            'bank services kept with matching counterpart' => [
                '- · Banko paslaugos',
                '- · Banko paslaugos',
                'Banko paslaugos',
            ],
            'refund text kept' => [
                'FT26169STCJIBFT3496 · REFUND 2622153198 8MWH3K',
                'FT26169STCJIBFT3496 · REFUND 2622153198 8MWH3K',
                'REFUND 2622153198 8MWH3K',
            ],
            'truncated multi invoice list prefers full list' => [
                '1004150,1004164,1003 · 1004150,1004164,1003946,1004110',
                '1004150,1004164,1003946,1004110',
            ],
            'seb pdf between accounts' => [
                'Mokej. banko viduje CBVILT2X AB SEB BANKAS Tarp savo sąskaitų (Between account), dok. nr. 359, mok. ident. kodas 306740042, operacijos nr. RO2417745530L02 indelininkui_formos_santrauka.pdf.',
                'Tarp savo sąskaitų (Between account)',
            ],
            'seb pdf invoice code' => [
                'Mokej. banko viduje CBVILT2X AB SEB BANKAS KAR13135, dok. nr. 358, mok. ident. kodas 306740042, operacijos nr. RO2410031491L01',
                'KAR13135',
            ],
            'seb pdf tex invoice' => [
                'Mokej. banko viduje CBVILT2X AB SEB BANKAS TEX00001262, mok. ident. kodas 306740042, gav. ident. kodas 307135904, operacijos nr. RO2410030554L01',
                'TEX00001262',
            ],
            'seb pdf european payment ref' => [
                'Europinis mokejimas LT824010044200521438 AGBLLT2X LUMINOR BANK AS LITHUANIAN BRANCH 2020217092254, mok. ident. kodas 306740042, gav. ident. kodas 145787276, operacijos nr. RD300158548',
                '2020217092254',
            ],
            'seb pdf salary note' => [
                'Mokej. banko viduje SEB bankas Atlyginimas už 07 mėn. (salary), dok. nr. 347, operacijos nr. RO2384356748L01',
                'Atlyginimas už 07 mėn. (salary)',
            ],
            'seb pdf bank fee' => [
                'Komisinis atlyginimas SEB bankas Mokestis RM027278811, operacijos nr. RO2384081985L03',
                'Mokestis RM027278811',
            ],
            'seb pdf ocr accent channel' => [
                'Europinis mokéjimas AS CITADELE BANKA LIETUVOS FILIALAS 126-1970, dok. nr. 350, mok. ident. kodas 306740042, operacijos nr. RD297638819',
                '126-1970',
            ],
            'seb pdf gav ident comma ocr' => [
                'EFVS2607849, mok. ident. kodas 306740042, gav. ident, kodas 123619023, operacijos nr. RO2387754120L01',
                'EFVS2607849',
            ],
            'seb pdf group transfer purpose' => [
                'Už grupini pinigy pervedima i kita banką, data 03/08/26, nr.347, suma 5720.28 Eur, operacijos nr. RO2384357161L01',
                'Už grupini pinigy pervedima i kita banką',
            ],
            'seb pdf ministerijos fragment' => [
                'MINISTERIJOS LT057044060007887175 CBVILT2X AB SEB BANKAS , 1311, dok. nr. 348, mok. ident. kodas 306740042, operacijos nr. RO2384358373L01',
                '1311',
            ],
        ];
    }
}
