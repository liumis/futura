<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->foreignId('mail_template_id')
                ->nullable()
                ->after('email')
                ->constrained('mail_templates')
                ->nullOnDelete();
        });

        Schema::table('cargos', function (Blueprint $table) {
            $table->foreignId('customs_contact_id')
                ->nullable()
                ->after('additional_cost')
                ->constrained('customs_contacts')
                ->nullOnDelete();

            $table->json('customs_documents')
                ->nullable()
                ->after('customs_invoice_sharepoint_path');

            $table->timestamp('customs_email_sent_at')
                ->nullable()
                ->after('customs_documents');
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customs_contact_id');
            $table->dropColumn(['customs_documents', 'customs_email_sent_at']);
        });

        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_template_id');
        });
    }
};
