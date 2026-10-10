<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_contracts')) {
            return;
        }

        Schema::table('employee_contracts', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_contracts', 'termination_date')) {
                $table->date('termination_date')->nullable()->after('valid_to');
            }
            if (! Schema::hasColumn('employee_contracts', 'termination_reason')) {
                $table->text('termination_reason')->nullable()->after('termination_date');
            }
            if (! Schema::hasColumn('employee_contracts', 'termination_document_id')) {
                $table->foreignId('termination_document_id')
                    ->nullable()
                    ->after('document_id')
                    ->constrained('documents')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_contracts')) {
            return;
        }

        Schema::table('employee_contracts', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_contracts', 'termination_document_id')) {
                $table->dropConstrainedForeignId('termination_document_id');
            }
            if (Schema::hasColumn('employee_contracts', 'termination_reason')) {
                $table->dropColumn('termination_reason');
            }
            if (Schema::hasColumn('employee_contracts', 'termination_date')) {
                $table->dropColumn('termination_date');
            }
        });
    }
};
