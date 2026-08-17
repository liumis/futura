<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipping_settings', 'name')) {
            Schema::table('shipping_settings', function (Blueprint $table): void {
                $table->string('name')->default('Default')->after('id');
            });
        }

        if (! Schema::hasColumn('shipping_settings', 'is_default')) {
            Schema::table('shipping_settings', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->after('name');
                $table->index('is_default');
            });
        }

        $rows = DB::table('shipping_settings')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            DB::table('shipping_settings')->insert([
                'name' => 'Default',
                'is_default' => true,
                'items_on_euroaluse' => 1,
                'euroaluse_price' => 0,
                'default_buffer' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            foreach ($rows as $index => $row) {
                DB::table('shipping_settings')->where('id', $row->id)->update([
                    'name' => $index === 0 ? 'Default' : 'Provider '.($index + 1),
                    'is_default' => $index === 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shipping_settings', function (Blueprint $table): void {
            $table->dropIndex(['is_default']);
            $table->dropColumn(['name', 'is_default']);
        });
    }
};
