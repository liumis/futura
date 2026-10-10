<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('directory_contact_comments')) {
            Schema::create('directory_contact_comments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('directory_contact_id')
                    ->constrained('directory_contacts')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->date('commented_at');
                $table->text('comment');
                $table->timestamps();

                $table->index(['directory_contact_id', 'commented_at'], 'dir_contact_comments_contact_date_idx');
            });
        }

        if (! Schema::hasTable('directory_contacts') || ! Schema::hasColumn('directory_contacts', 'comment')) {
            return;
        }

        DB::table('directory_contacts')
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderBy('id')
            ->each(function (object $contact): void {
                $alreadyCopied = DB::table('directory_contact_comments')
                    ->where('directory_contact_id', $contact->id)
                    ->exists();

                if ($alreadyCopied) {
                    return;
                }

                DB::table('directory_contact_comments')->insert([
                    'directory_contact_id' => $contact->id,
                    'user_id' => null,
                    'commented_at' => Carbon::parse($contact->updated_at ?? now())->toDateString(),
                    'comment' => $contact->comment,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_contact_comments');
    }
};
