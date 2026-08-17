<?php

namespace App\Models;

use App\Services\EmailTestMode;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'email_test_mode',
        'low_stock_alert_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_test_mode' => 'boolean',
            'low_stock_alert_limit' => 'decimal:3',
        ];
    }

    public function lowStockAlertLimit(): float
    {
        return (float) ($this->low_stock_alert_limit ?? 0);
    }

    public static function instance(): self
    {
        return static::query()->firstOrCreate([]);
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            EmailTestMode::clearCache();
        });

        static::deleted(function (): void {
            EmailTestMode::clearCache();
        });
    }
}
