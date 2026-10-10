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
            if (! Schema::hasColumn('directory_contacts', 'type')) {
                $table->string('type')->nullable()->after('company_name');
            }

            if (! Schema::hasColumn('directory_contacts', 'segment')) {
                $table->string('segment')->nullable()->after('country');
            }

            if (! Schema::hasColumn('directory_contacts', 'priority')) {
                $table->string('priority')->nullable()->after('segment');
            }

            if (! Schema::hasColumn('directory_contacts', 'website')) {
                $table->string('website')->nullable()->after('priority');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('directory_contacts')) {
            return;
        }

        Schema::table('directory_contacts', function (Blueprint $table): void {
            foreach (['type', 'segment', 'priority', 'website'] as $column) {
                if (Schema::hasColumn('directory_contacts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
