<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('leave_requests', 'payment_gross')) {
                $table->decimal('payment_gross', 12, 2)->nullable()->after('comment');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_requests')) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('leave_requests', 'payment_gross')) {
                $table->dropColumn('payment_gross');
            }
        });
    }
};
