<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->string('company_name')->nullable()->change();
        });

        $keepId = DB::table('customs_contacts')->orderBy('id')->value('id');

        if ($keepId !== null) {
            DB::table('customs_contacts')->where('id', '!=', $keepId)->delete();
        }
    }

    public function down(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->string('company_name')->nullable(false)->change();
        });
    }
};
