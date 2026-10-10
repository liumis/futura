<?php

namespace App\Services;

use App\Models\ExpenseType;
use App\Models\InvoiceCode;

/**
 * Maps expense categories to Didžioji knyga accounts (invoice codes).
 */
class ExpenseTypeAccountMapper
{
    /**
     * Default expense type name → account code.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        'Administracija' => '6312',
        'IT' => '6312',
        'Transportas' => '6208',
        'Marketingas' => '6202',
        'Paslaugos' => '6302',
        'Atlyginimai' => '6304',
        'Prekės' => '2040',
        'Mokesčiai' => '63083',
        'Ilgalaikis turtas' => '1220',
        'Kita' => '6401',
        'Kreditinė sąskaita' => '6401',
    ];

    public const FALLBACK_CODE = '6401';

    /**
     * Ensure every expense type has an invoice_code_id (creates missing links).
     *
     * @return int Number of expense types updated
     */
    public static function syncDefaults(): int
    {
        $updated = 0;

        foreach (ExpenseType::query()->orderBy('id')->get() as $type) {
            if ($type->invoice_code_id) {
                continue;
            }

            $code = self::DEFAULTS[$type->name] ?? self::FALLBACK_CODE;
            $invoiceCode = self::ensureCode($code);
            $type->forceFill(['invoice_code_id' => $invoiceCode->id])->save();
            $updated++;
        }

        return $updated;
    }

    public static function codeFor(?ExpenseType $type): InvoiceCode
    {
        if ($type !== null) {
            if ($type->invoice_code_id) {
                $type->loadMissing('invoiceCode');
                if ($type->invoiceCode instanceof InvoiceCode) {
                    return $type->invoiceCode;
                }
            }

            $preferred = self::DEFAULTS[$type->name] ?? self::FALLBACK_CODE;

            return self::ensureCode($preferred);
        }

        return self::ensureCode(self::FALLBACK_CODE);
    }

    public static function ensureCode(string $code, ?string $name = null): InvoiceCode
    {
        $existing = InvoiceCode::query()->where('code', $code)->first();
        if ($existing !== null) {
            return $existing;
        }

        return InvoiceCode::query()->create([
            'code' => $code,
            'name' => $name ?: $code,
        ]);
    }
}
