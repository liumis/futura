<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_trip_requests')) {
            return;
        }

        Schema::table('business_trip_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('business_trip_requests', 'expenses_amount')) {
                $table->decimal('expenses_amount', 12, 2)
                    ->nullable()
                    ->after('payable_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('business_trip_requests') || ! Schema::hasColumn('business_trip_requests', 'expenses_amount')) {
            return;
        }

        Schema::table('business_trip_requests', function (Blueprint $table): void {
            $table->dropColumn('expenses_amount');
        });
    }
};
