<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultProviderId = DB::table('shipping_settings')
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id')
            ?? DB::table('shipping_settings')->orderBy('id')->value('id');

        if ($defaultProviderId === null) {
            $defaultProviderId = DB::table('shipping_settings')->insertGetId([
                'name' => 'Default',
                'is_default' => true,
                'items_on_euroaluse' => 1,
                'euroaluse_price' => 0,
                'default_buffer' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('destinations', function (Blueprint $table): void {
            $table->unsignedBigInteger('shipping_setting_id')->nullable()->after('id');
        });

        DB::table('destinations')
            ->whereNull('shipping_setting_id')
            ->update(['shipping_setting_id' => $defaultProviderId]);

        Schema::table('destinations', function (Blueprint $table): void {
            $table->foreign('shipping_setting_id')
                ->references('id')
                ->on('shipping_settings')
                ->restrictOnDelete();
            $table->index('shipping_setting_id');
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table): void {
            $table->dropForeign(['shipping_setting_id']);
            $table->dropIndex(['shipping_setting_id']);
            $table->dropColumn('shipping_setting_id');
        });
    }
};
