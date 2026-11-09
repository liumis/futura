<?php

use App\Services\FuturaCustomerLevelBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * F kainoraštis → customer group C1; K kainoraštis → group K (Karmman).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_levels')
            || ! Schema::hasTable('customer_level_prices')
            || ! Schema::hasTable('users')
            || ! Schema::hasTable('collections')) {
            return;
        }

        app(FuturaCustomerLevelBootstrap::class)->run();
    }

    public function down(): void
    {
        // Keep price groups; assignments are operational data.
    }
};
