<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->boolean('has_finances')->default(false)->after('description');
            $table->decimal('total_income', 12, 2)->nullable()->after('has_finances');
            $table->decimal('income_left', 12, 2)->nullable()->after('total_income');
            $table->decimal('total_payment', 12, 2)->nullable()->after('income_left');
            $table->decimal('payment_left', 12, 2)->nullable()->after('total_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table) {
            $table->dropColumn([
                'has_finances',
                'total_income',
                'income_left',
                'total_payment',
                'payment_left',
            ]);
        });
    }
};
