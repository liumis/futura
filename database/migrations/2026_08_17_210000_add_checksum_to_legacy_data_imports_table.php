<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('legacy_data_imports', 'checksum')) {
            return;
        }

        Schema::table('legacy_data_imports', function (Blueprint $table): void {
            $table->string('checksum', 64)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('legacy_data_imports', function (Blueprint $table): void {
            $table->dropColumn('checksum');
        });
    }
};
