<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->unsignedInteger('default_order')->default(10)->change();
        });

        DB::table('colors')->update(['default_order' => 10]);
    }

    public function down(): void
    {
        Schema::table('colors', function (Blueprint $table) {
            $table->unsignedInteger('default_order')->default(100)->change();
        });

        DB::table('colors')->update(['default_order' => 100]);
    }
};
