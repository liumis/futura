<?php

use App\Enums\CargoStatus;
use App\Models\Cargo;
use App\Models\ImportTaxPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_tax_payments_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cargo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_tax_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->decimal('line_value', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->date('received_date');
            $table->json('documents')->nullable();
            $table->timestamps();

            $table->unique('cargo_id');
            $table->index('received_date');
        });

        $hasDocumentsColumn = Schema::hasColumn('import_tax_payments', 'documents');

        $cargoIds = DB::table('import_tax_payments')
            ->whereNotNull('cargo_id')
            ->distinct()
            ->pluck('cargo_id');

        foreach ($cargoIds as $cargoId) {
            $rows = DB::table('import_tax_payments')->where('cargo_id', $cargoId)->get();
            $first = $rows->first();

            if ($first === null) {
                continue;
            }

            $documents = null;

            if ($hasDocumentsColumn) {
                $documents = $rows
                    ->pluck('documents')
                    ->filter(fn ($value): bool => filled($value))
                    ->first();
            }

            DB::table('import_tax_payments_new')->insert([
                'cargo_id' => $cargoId,
                'import_tax_id' => $first->import_tax_id,
                'tax_rate' => $first->tax_rate,
                'amount' => $rows->sum('amount'),
                'line_value' => $rows->sum('line_value'),
                'tax_amount' => $rows->sum('tax_amount'),
                'received_date' => $rows->min('received_date'),
                'documents' => $documents,
                'created_at' => $rows->min('created_at'),
                'updated_at' => now(),
            ]);
        }

        Schema::drop('import_tax_payments');
        Schema::rename('import_tax_payments_new', 'import_tax_payments');

        Cargo::query()
            ->where('status', CargoStatus::Received)
            ->each(function (Cargo $cargo): void {
                ImportTaxPayment::syncFromCargo($cargo);
            });
    }

    public function down(): void
    {
        Schema::create('import_tax_payments_old', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('cargo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('import_tax_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedInteger('amount')->default(0);
            $table->decimal('line_value', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->date('received_date');
            $table->timestamps();

            $table->unique(['cargo_id', 'product_id']);
            $table->index('received_date');
        });

        Schema::drop('import_tax_payments');
        Schema::rename('import_tax_payments_old', 'import_tax_payments');
    }
};
