<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_stable_forecast')->default(false)->after('customer_level_id');
        });

        // Seed known stable B2B accounts (BIKINIS / BISKINIS naming variants).
        DB::table('users')
            ->where(function ($query): void {
                $query->where('company_name', 'like', '%bikini%')
                    ->orWhere('company_name', 'like', '%biskini%')
                    ->orWhere('name', 'like', '%bikini%')
                    ->orWhere('name', 'like', '%biskini%');
            })
            ->update(['is_stable_forecast' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_stable_forecast');
        });
    }
};
