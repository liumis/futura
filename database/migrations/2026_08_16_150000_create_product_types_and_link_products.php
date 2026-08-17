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
        if (! Schema::hasTable('product_types')) {
            Schema::create('product_types', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('key')->unique();
                $table->boolean('requires_color')->default(true);
                $table->timestamps();
            });
        }

        $now = now();

        foreach ([
            ['name' => 'Artificial leather', 'key' => 'artificial_leather', 'requires_color' => true],
            ['name' => 'Catalog', 'key' => 'catalog', 'requires_color' => false],
        ] as $type) {
            DB::table('product_types')->updateOrInsert(
                ['key' => $type['key']],
                [
                    'name' => $type['name'],
                    'requires_color' => $type['requires_color'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $leatherId = (int) DB::table('product_types')->where('key', 'artificial_leather')->value('id');

        if (! Schema::hasColumn('products', 'product_type_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignId('product_type_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('product_types')
                    ->restrictOnDelete();
            });
        }

        if ($leatherId > 0) {
            DB::table('products')->whereNull('product_type_id')->update([
                'product_type_id' => $leatherId,
            ]);
        }

        $driver = Schema::getConnection()->getDriverName();
        $color = collect(Schema::getColumns('products'))->firstWhere('name', 'color_id');
        $colorIsNullable = (bool) ($color['nullable'] ?? false);

        if (! $colorIsNullable && Schema::hasColumn('products', 'color_id')) {
            if ($driver === 'sqlite') {
                Schema::disableForeignKeyConstraints();

                Schema::dropIfExists('products_tmp');

                Schema::create('products_tmp', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('product_type_id')->constrained('product_types')->restrictOnDelete();
                    $table->string('name')->default('20');
                    $table->foreignId('color_id')->nullable()->constrained('colors')->nullOnDelete();
                    $table->string('product_code');
                    $table->string('alternative_code')->nullable();
                    $table->string('dsv_code')->nullable();
                    $table->decimal('default_cost', 12, 2)->default(0);
                    $table->unsignedInteger('current_amount')->default(0);
                    $table->timestamps();
                });

                $columns = [
                    'id',
                    'product_type_id',
                    'name',
                    'color_id',
                    'product_code',
                    'alternative_code',
                    'dsv_code',
                    'default_cost',
                    'current_amount',
                    'created_at',
                    'updated_at',
                ];

                $existing = array_intersect($columns, Schema::getColumnListing('products'));
                $select = implode(', ', $existing);

                DB::statement("INSERT INTO products_tmp ({$select}) SELECT {$select} FROM products");

                Schema::drop('products');
                Schema::rename('products_tmp', 'products');
                Schema::enableForeignKeyConstraints();
            } else {
                SchemaForeignKeys::dropOnColumn('products', 'color_id');

                Schema::table('products', function (Blueprint $table): void {
                    $table->unsignedBigInteger('color_id')->nullable()->change();
                    $table->foreign('color_id')->references('id')->on('colors')->nullOnDelete();
                });
            }
        }

        if ($driver === 'mysql' && Schema::hasColumn('products', 'product_type_id')) {
            DB::statement('ALTER TABLE products MODIFY product_type_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql' && Schema::hasColumn('products', 'product_type_id')) {
            DB::statement('ALTER TABLE products ALTER COLUMN product_type_id SET NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            Schema::dropIfExists('product_types');

            return;
        }

        $leatherId = DB::table('product_types')->where('key', 'artificial_leather')->value('id');

        if ($leatherId) {
            DB::table('products')->whereNull('color_id')->delete();
        }

        SchemaForeignKeys::dropColumnIfExists('products', 'product_type_id');
        Schema::dropIfExists('product_types');
    }
};
