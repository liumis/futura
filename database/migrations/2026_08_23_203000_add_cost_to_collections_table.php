<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('collections', 'cost')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->decimal('cost', 12, 2)->default(0)->after('price');
            });
        }

        if (Schema::hasColumn('collections', 'price') && Schema::hasColumn('collections', 'cost')) {
            DB::table('collections')->where('cost', 0)->update([
                'cost' => DB::raw('price'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('collections', 'cost')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->dropColumn('cost');
            });
        }
    }
};
