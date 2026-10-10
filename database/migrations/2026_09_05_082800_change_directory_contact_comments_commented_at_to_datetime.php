<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_contact_comments')) {
            return;
        }

        Schema::table('directory_contact_comments', function (Blueprint $table): void {
            $table->dateTime('commented_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('directory_contact_comments')) {
            return;
        }

        Schema::table('directory_contact_comments', function (Blueprint $table): void {
            $table->date('commented_at')->nullable(false)->change();
        });
    }
};
