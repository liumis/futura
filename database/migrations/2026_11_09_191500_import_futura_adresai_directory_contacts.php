<?php

use App\Services\DirectoryContactExcelImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Import Futura address book (Darbai_3 → Adresai → Futura adresų knyga) into CRM.
 * Leaves last_contact empty on brand-new cards so they appear under
 * Dashboard → "New and never contacted".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('directory_contacts')) {
            return;
        }

        $path = database_path('data/futura-adresai-import.xlsx');
        if (! is_file($path)) {
            return;
        }

        app(DirectoryContactExcelImporter::class)->import(
            $path,
            'futura-adresai-import.xlsx',
        );
    }

    public function down(): void
    {
        // Keep imported CRM contacts.
    }
};
