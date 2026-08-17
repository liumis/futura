<?php

use App\Enums\CargoStatus;
use App\Models\Cargo;
use App\Models\ImportTaxPayment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_tax_payments', function (Blueprint $table) {
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

        $lastReceivedCargo = Cargo::query()
            ->where('status', CargoStatus::Received)
            ->latest('id')
            ->first();

        if ($lastReceivedCargo !== null) {
            ImportTaxPayment::syncFromCargo($lastReceivedCargo);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_tax_payments');
    }
};
