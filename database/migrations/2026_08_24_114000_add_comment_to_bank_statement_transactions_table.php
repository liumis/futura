<?php

use App\Models\BankStatementImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (! Schema::hasColumn('bank_statement_transactions', 'comment')) {
                $table->text('comment')->nullable();
            }
        });

        if (! Schema::hasColumn('bank_statement_imports', 'comment')) {
            return;
        }

        BankStatementImport::query()
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->with('transactions')
            ->get()
            ->each(function (BankStatementImport $import): void {
                $import->transactions()
                    ->where(function ($query): void {
                        $query->whereNull('comment')->orWhere('comment', '');
                    })
                    ->update(['comment' => $import->comment]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        Schema::table('bank_statement_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('bank_statement_transactions', 'comment')) {
                $table->dropColumn('comment');
            }
        });
    }
};
