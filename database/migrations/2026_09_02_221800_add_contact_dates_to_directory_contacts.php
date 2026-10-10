<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_contacts')) {
            return;
        }

        Schema::table('directory_contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('directory_contacts', 'last_contact')) {
                $table->date('last_contact')->nullable()->after('directory_contact_stage_id');
            }

            if (! Schema::hasColumn('directory_contacts', 'next_contact')) {
                $table->date('next_contact')->nullable()->after('last_contact');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('directory_contacts')) {
            return;
        }

        Schema::table('directory_contacts', function (Blueprint $table): void {
            if (Schema::hasColumn('directory_contacts', 'next_contact')) {
                $table->dropColumn('next_contact');
            }

            if (Schema::hasColumn('directory_contacts', 'last_contact')) {
                $table->dropColumn('last_contact');
            }
        });
    }
};
