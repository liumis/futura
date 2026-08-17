<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_request_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color', 20)->default('#0ea5e9');
            $table->timestamps();
        });

        Schema::create('overtime_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->foreignId('overtime_request_type_id')->constrained('overtime_request_types')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->string('status')->default('new');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['date_from', 'date_to']);
            $table->index('status');
        });

        Schema::create('overtime_request_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('overtime_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['overtime_request_id', 'user_id'], 'overtime_request_approver_user_unique');
        });

        $now = now();

        foreach ([
            ['name' => 'Viršvalandžiai', 'color' => '#0ea5e9'],
            ['name' => 'Darbas poilsio dieną', 'color' => '#6366f1'],
            ['name' => 'Darbas švenčių dieną', 'color' => '#db2777'],
            ['name' => 'Kita', 'color' => '#64748b'],
        ] as $type) {
            DB::table('overtime_request_types')->updateOrInsert(
                ['name' => $type['name']],
                [
                    'color' => $type['color'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_request_approvers');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('overtime_request_types');
    }
};
