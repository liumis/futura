<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('collections', 'price')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table) {
            $table->decimal('price', 12, 2)->default(0)->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('collections', 'price')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
