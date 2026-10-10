<?php

use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'default_finance_type')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->string('default_finance_type')->nullable()->after('private_provider');
            });
        }

        Contact::prefillKnownDefaultCategories();
    }

    public function down(): void
    {
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'default_finance_type')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropColumn('default_finance_type');
            });
        }
    }
};
