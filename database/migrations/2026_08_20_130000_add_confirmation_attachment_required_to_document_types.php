<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            if (! Schema::hasColumn('document_types', 'confirmation_attachment_required')) {
                $table->boolean('confirmation_attachment_required')
                    ->default(false)
                    ->after('periodical_signature_months');
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            if (Schema::hasColumn('document_types', 'confirmation_attachment_required')) {
                $table->dropColumn('confirmation_attachment_required');
            }
        });
    }
};
