<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cargo_items')) {
            Schema::create('cargo_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cargo_id')->constrained('cargos')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('amount');
                $table->timestamps();

                $table->index(['cargo_id', 'product_id']);
            });
        }

        if (Schema::hasTable('cargos') && Schema::hasColumn('cargos', 'product_id')) {
            $cargos = DB::table('cargos')
                ->whereNotNull('product_id')
                ->get(['id', 'product_id', 'amount']);

            foreach ($cargos as $cargo) {
                DB::table('cargo_items')->insert([
                    'cargo_id' => $cargo->id,
                    'product_id' => $cargo->product_id,
                    'amount' => $cargo->amount,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('cargos') && Schema::hasColumn('cargos', 'product_id')) {
            // SQLite: dropping a column can fail if a leftover index still references it.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS cargos_product_id_index');
            }

            Schema::table('cargos', function (Blueprint $table) {
                $table->dropConstrainedForeignId('product_id');
            });
        }

        if (Schema::hasTable('cargos') && Schema::hasColumn('cargos', 'amount')) {
            Schema::table('cargos', function (Blueprint $table) {
                $table->dropColumn('amount');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_items');

        Schema::table('cargos', function (Blueprint $table) {
            $table->foreignId('product_id')->after('id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('amount')->default(0)->after('estimated_arrival');
        });
    }
};
