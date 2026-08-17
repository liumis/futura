<?php

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

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('color_id')
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        // MySQL will not drop an index that still backs a foreign key, even in the
        // same ALTER as DROP FOREIGN KEY. Drop the constraint first, then columns.
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['collection_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasIndex('products', 'products_collection_id_index')) {
                $table->dropIndex(['collection_id']);
            }

            $table->dropColumn(['collection_id', 'color_code', 'color_name']);
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
