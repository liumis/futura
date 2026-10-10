<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->restrictOnDelete();
            $table->string('color_code');
            $table->string('color_name');
            $table->timestamps();

            $table->unique(['collection_id', 'color_name']);
            $table->index('collection_id');
        });

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'collection_id')) {
            $uniqueColors = DB::table('products')
                ->select('collection_id', 'color_name', DB::raw('MIN(color_code) as color_code'))
                ->groupBy('collection_id', 'color_name')
                ->get();

            foreach ($uniqueColors as $row) {
                DB::table('colors')->insert([
                    'collection_id' => $row->collection_id,
                    'color_code' => $row->color_code,
                    'color_name' => $row->color_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('order_items')) {
            DB::table('order_items')->delete();
        }

        if (Schema::hasTable('cargo_items')) {
            DB::table('cargo_items')->delete();
        }

        if (Schema::hasTable('products')) {
            DB::table('products')->delete();
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite cannot DROP COLUMN when a FK still references it; rebuild the table.
            Schema::disableForeignKeyConstraints();

            Schema::dropIfExists('products_tmp');

            Schema::create('products_tmp', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('color_id')->constrained()->restrictOnDelete();

                if (Schema::hasColumn('products', 'name')) {
                    $table->string('name')->default('');
                }

                if (Schema::hasColumn('products', 'product_code')) {
                    $table->string('product_code');
                }

                if (Schema::hasColumn('products', 'alternative_code')) {
                    $table->string('alternative_code')->nullable();
                }

                $table->unsignedInteger('current_amount')->default(0);
                $table->timestamps();
            });

            Schema::drop('products');
            Schema::rename('products_tmp', 'products');
            Schema::enableForeignKeyConstraints();

            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('color_id')
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        SchemaForeignKeys::dropOnColumn('products', 'collection_id');

        Schema::table('products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['collection_id', 'color_code', 'color_name'],
                fn (string $column): bool => Schema::hasColumn('products', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('collection_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('color_code')->default('');
            $table->string('color_name')->default('');

            $table->dropForeign(['color_id']);
            $table->dropColumn('color_id');
        });

        Schema::dropIfExists('colors');
    }
};
