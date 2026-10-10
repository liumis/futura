<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->decimal('port_price', 12, 4)->nullable()->after('cost');
            $table->decimal('agent_fee', 12, 4)->nullable()->after('port_price');
            $table->decimal('other_cost', 12, 4)->nullable()->after('agent_fee');
            $table->decimal('markup_percent', 8, 2)->nullable()->after('other_cost');
        });

        $rows = DB::table('collections')->select('id', 'price', 'cost')->get();

        foreach ($rows as $row) {
            $cost = $row->cost !== null ? (float) $row->cost : null;
            $price = $row->price !== null ? (float) $row->price : null;
            $markup = null;

            if ($cost !== null && $cost > 0 && $price !== null && $price > 0) {
                $markup = round((($price / $cost) - 1) * 100, 2);
            }

            // Do not backfill port_price from cost — cost is already savikaina.
            DB::table('collections')->where('id', $row->id)->update([
                'markup_percent' => $markup,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn(['port_price', 'agent_fee', 'other_cost', 'markup_percent']);
        });
    }
};
