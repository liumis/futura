<?php

use App\Services\UtfMojibakeRepairer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Repair CP437/OEM mojibake already stored in CRM comments/names (d─ùl → dėl).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('directory_contact_comments')
            && ! Schema::hasTable('directory_contacts')) {
            return;
        }

        UtfMojibakeRepairer::repairDirectoryContacts(null, dryRun: false);
    }

    public function down(): void
    {
        // Irreversible content repair.
    }
};
