<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->text('email_signature')->nullable()->after('mail_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->dropColumn('email_signature');
        });
    }
};
