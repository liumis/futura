<?php

use App\Enums\DividendPaymentReportStatus;
use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dividend_payment_reports')) {
            Schema::create('dividend_payment_reports', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('status', 40)->default(DividendPaymentReportStatus::Created->value);
                $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasTable('dividend_payment_report_approvers')) {
            Schema::create('dividend_payment_report_approvers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('dividend_payment_report_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('approved_at')->nullable();
                $table->boolean('is_auto_approved')->default(false);
                $table->timestamps();

                $table->unique(['dividend_payment_report_id', 'user_id'], 'dividend_report_user_unique');
            });
        }

        SchemaForeignKeys::ensure(
            'dividend_payment_report_approvers',
            'dividend_payment_report_id',
            'dividend_payment_reports',
            'dpr_approvers_report_fk',
        );
        SchemaForeignKeys::ensure(
            'dividend_payment_report_approvers',
            'user_id',
            'users',
            'dpr_approvers_user_fk',
        );

        if (! Schema::hasColumn('dividends', 'gpm_amount')) {
            Schema::table('dividends', function (Blueprint $table): void {
                $table->decimal('gpm_amount', 12, 2)->nullable()->after('amount');
                $table->decimal('net_amount', 12, 2)->nullable()->after('gpm_amount');
                $table->string('status', 40)->default('open')->after('net_amount');
                $table->string('comment')->nullable()->after('status');
                $table->boolean('is_paid')->default(false)->after('comment');
                $table->timestamp('paid_at')->nullable()->after('is_paid');

                $table->foreignId('dividend_payment_report_id')
                    ->nullable()
                    ->after('paid_at')
                    ->constrained('dividend_payment_reports')
                    ->nullOnDelete();

                $table->index(['status', 'employee_id', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('dividends', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dividend_payment_report_id');
            $table->dropColumn(['gpm_amount', 'net_amount', 'status', 'comment', 'is_paid', 'paid_at']);
        });

        Schema::dropIfExists('dividend_payment_report_approvers');
        Schema::dropIfExists('dividend_payment_reports');
    }
};
