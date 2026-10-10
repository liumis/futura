<?php

namespace Tests\Unit;

use App\Services\LithuanianCompanyNameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LithuanianCompanyNameNormalizerTest extends TestCase
{
    #[DataProvider('names')]
    public function test_normalizes_lithuanian_company_names(string $input, string $expected): void
    {
        $this->assertSame($expected, LithuanianCompanyNameNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'quoted uab prefix' => ['UAB "VINILAS"', 'Vinilas, UAB'],
            'uab prefix caps' => ['UAB METIDA', 'Metida, UAB'],
            'quoted autoresta' => ['UAB "Autoresta"', 'Autoresta, UAB'],
            'mb suffix' => ['Textelita MB', 'Textelita, MB'],
            'mb prefix' => ['MB Liumis', 'Liumis, MB'],
            'already good' => ['Finansų valdymo sistemos, UAB', 'Finansų valdymo sistemos, UAB'],
            'missing comma' => ['DPD Lietuva UAB', 'DPD Lietuva, UAB'],
            'vsį trailing' => ['Šiaulių regiono atliekų tvarkymo centras,VšĮ', 'Šiaulių regiono atliekų tvarkymo centras, VšĮ'],
            'dsv' => ['DSV ROAD UAB', 'DSV Road, UAB'],
        ];
    }
}
