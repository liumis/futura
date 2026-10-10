<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_types', 'periodical_signature_needed')) {
                $table->boolean('periodical_signature_needed')->default(false)->after('group_by_year');
            }

            if (! Schema::hasColumn('document_types', 'periodical_signature_months')) {
                $table->unsignedSmallInteger('periodical_signature_months')->nullable()->after('periodical_signature_needed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            if (Schema::hasColumn('document_types', 'periodical_signature_months')) {
                $table->dropColumn('periodical_signature_months');
            }

            if (Schema::hasColumn('document_types', 'periodical_signature_needed')) {
                $table->dropColumn('periodical_signature_needed');
            }
        });
    }
};
