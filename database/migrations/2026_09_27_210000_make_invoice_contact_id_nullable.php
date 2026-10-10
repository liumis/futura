<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'contact_id')) {
            return;
        }

        if ($this->contactIdAlreadyNullable()) {
            $this->ensureNullOnDelete();

            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteNullableContactId();

            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreign('contact_id')
                ->references('id')
                ->on('contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Irreversible on SQLite once invoices may have null contact_id.
    }

    private function contactIdAlreadyNullable(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return false;
        }

        $column = collect(DB::select('PRAGMA table_info(invoices)'))
            ->first(fn ($col): bool => $col->name === 'contact_id');

        return $column !== null && (int) $column->notnull === 0;
    }

    private function ensureNullOnDelete(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $fk = collect(DB::select('PRAGMA foreign_key_list(invoices)'))
            ->first(fn ($row): bool => $row->from === 'contact_id');

        if ($fk !== null && strtoupper((string) $fk->on_delete) === 'SET NULL') {
            return;
        }

        $this->rebuildSqliteNullableContactId();
    }

    private function rebuildSqliteNullableContactId(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        Schema::dropIfExists('invoices_tmp_contact_nullable');

        Schema::create('invoices_tmp_contact_nullable', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->date('invoice_date');
            $table->date('upload_date');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('pdf_path')->nullable();
            $table->timestamps();
            $table->decimal('sum_without_vat', 12, 2)->default(0);
            $table->decimal('vat', 12, 2)->default(0);
            $table->decimal('sum_inc_vat', 12, 2)->default(0);
            $table->longText('file_content')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime')->nullable();
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->foreignId('invoice_series_id')->nullable()->constrained('invoice_series')->nullOnDelete();
            $table->unsignedInteger('series_number')->nullable();
            $table->string('invoice_number')->nullable()->unique();
            $table->foreignId('income_type_id')->nullable()->constrained('income_types')->nullOnDelete();
            $table->foreignId('expense_type_id')->nullable()->constrained('expense_types')->nullOnDelete();
            $table->foreignId('vat_rate_id')->nullable()->constrained('vat_rates')->nullOnDelete();
            $table->string('sharepoint_web_url', 2000)->nullable();
            $table->string('sharepoint_item_id')->nullable();
            $table->string('sharepoint_path', 1000)->nullable();
            $table->json('additional_documents')->nullable();
            $table->unsignedInteger('payment_term')->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->boolean('paid')->default(false);
            $table->boolean('private_provider')->default(false);
            $table->boolean('is_own_account')->default(false);
            $table->json('sharepoint_files')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('currency_rate', 18, 6)->nullable();
            $table->decimal('sum_inc_vat_eur', 12, 2)->nullable();
            $table->softDeletes();
            $table->text('comment')->nullable();
        });

        $columns = collect(DB::select('PRAGMA table_info(invoices)'))
            ->pluck('name')
            ->all();

        $targetColumns = collect(DB::select('PRAGMA table_info(invoices_tmp_contact_nullable)'))
            ->pluck('name')
            ->all();

        $copyColumns = array_values(array_intersect($targetColumns, $columns));
        $columnList = implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $copyColumns));

        DB::statement(
            'INSERT INTO invoices_tmp_contact_nullable ('.$columnList.') SELECT '.$columnList.' FROM invoices'
        );

        Schema::drop('invoices');
        Schema::rename('invoices_tmp_contact_nullable', 'invoices');

        DB::statement('PRAGMA foreign_keys = ON');
    }
};
