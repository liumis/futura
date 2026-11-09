<?php

use App\Services\FuturaCustomerLevelBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Create price group V (Vinilas lower F rates) and move Vinilas off C1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_levels')
            || ! Schema::hasTable('customer_level_prices')
            || ! Schema::hasTable('users')) {
            return;
        }

        app(FuturaCustomerLevelBootstrap::class)->run();
    }

    public function down(): void
    {
        // Keep group V and assignments.
    }
};
