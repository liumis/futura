<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_trip_request_document', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_trip_request_id')
                ->constrained('business_trip_requests')
                ->cascadeOnDelete();
            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['business_trip_request_id', 'document_id'], 'btr_document_unique');
        });

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->foreignId('business_trip_request_id')
                ->nullable()
                ->after('document_id')
                ->constrained('business_trip_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('business_trip_request_id');
        });

        Schema::dropIfExists('business_trip_request_document');
    }
};
