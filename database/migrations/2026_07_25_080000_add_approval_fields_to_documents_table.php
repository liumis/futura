<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('user_uploaded_id')->nullable()->after('file_path')->constrained('users')->nullOnDelete();
            $table->boolean('flag_approved')->default(false)->after('user_uploaded_id');
            $table->foreignId('user_approved_id')->nullable()->after('flag_approved')->constrained('users')->nullOnDelete();
            $table->timestamp('approval_date')->nullable()->after('user_approved_id');
            $table->string('confirmed_ip', 45)->nullable()->after('approval_date');
            $table->text('confirmed_user_agent')->nullable()->after('confirmed_ip');
            $table->string('content_hash', 64)->nullable()->after('confirmed_user_agent');
            $table->string('pdf_hash', 64)->nullable()->after('content_hash');
            $table->string('approved_file_path')->nullable()->after('pdf_hash');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_uploaded_id');
            $table->dropConstrainedForeignId('user_approved_id');
            $table->dropColumn([
                'flag_approved',
                'approval_date',
                'confirmed_ip',
                'confirmed_user_agent',
                'content_hash',
                'pdf_hash',
                'approved_file_path',
            ]);
        });
    }
};
