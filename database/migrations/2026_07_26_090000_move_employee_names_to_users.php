<?php

use App\Models\Employee;
use App\Models\User;
use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->cascadeOnDelete();
        });

        Employee::query()->orderBy('id')->each(function (Employee $employee): void {
            $user = null;

            if (filled($employee->email)) {
                $user = User::query()->where('email', $employee->email)->first();
            }

            if ($user === null) {
                $baseEmail = filled($employee->email)
                    ? (string) $employee->email
                    : 'employee'.$employee->id.'@employees.local';
                $email = $baseEmail;
                $suffix = 1;

                while (User::query()->where('email', $email)->exists()) {
                    $email = $employee->id.'-'.$suffix.'-'.$baseEmail;
                    $suffix++;
                }

                $user = User::query()->create([
                    'name' => (string) ($employee->name ?: 'Employee'),
                    'surname' => $employee->surname,
                    'email' => $email,
                    'phone' => $employee->phone,
                    'password' => Hash::make(Str::random(40)),
                ]);
            } else {
                $user->fill([
                    'name' => filled($user->name) ? $user->name : (string) ($employee->name ?: 'Employee'),
                    'surname' => filled($user->surname) ? $user->surname : $employee->surname,
                    'phone' => filled($user->phone) ? $user->phone : $employee->phone,
                ])->save();
            }

            $employee->forceFill(['user_id' => $user->id])->save();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['name', 'surname']);
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        SchemaForeignKeys::dropOnColumn('employees', 'user_id');

        Schema::table('employees', function (Blueprint $table) {
            $table->string('name')->nullable()->after('user_id');
            $table->string('surname')->nullable()->after('name');
        });

        Employee::query()->orderBy('id')->each(function (Employee $employee): void {
            $user = User::query()->find($employee->user_id);

            $employee->forceFill([
                'name' => $user?->name ?: 'Employee',
                'surname' => $user?->surname ?: '',
            ])->save();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });
    }
};
