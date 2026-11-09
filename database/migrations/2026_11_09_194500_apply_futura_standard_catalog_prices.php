<?php

use App\Services\FuturaCustomerLevelBootstrap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Apply F kainoraštis €/m to collections.price and F katalogų kainos €/vnt to Catalog products.cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('collections') || ! Schema::hasTable('products')) {
            return;
        }

        app(FuturaCustomerLevelBootstrap::class)->applyStandardCollectionPrices();
        app(FuturaCustomerLevelBootstrap::class)->applyCatalogProductPrices();
    }

    public function down(): void
    {
        // Keep applied list prices.
    }
};
