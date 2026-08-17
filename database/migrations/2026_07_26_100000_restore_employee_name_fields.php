<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
            $table->string('surname')->default('')->after('name');
        });

        Employee::query()->orderBy('id')->each(function (Employee $employee): void {
            $user = filled($employee->user_id)
                ? User::query()->find($employee->user_id)
                : null;

            $employee->forceFill([
                'name' => filled($user?->name) ? (string) $user->name : 'Employee',
                'surname' => (string) ($user?->surname ?? ''),
            ])->save();
        });

        SchemaForeignKeys::dropOnColumn('employees', 'user_id');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->unique('user_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['name', 'surname']);
        });
    }
};
