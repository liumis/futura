<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $role = Role::findOrCreate('project manager');

        $user = User::query()->withoutGlobalScopes()->find(1);

        if ($user !== null && ! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    public function down(): void
    {
        $role = Role::query()
            ->where('name', 'project manager')
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            return;
        }

        $user = User::query()->withoutGlobalScopes()->find(1);

        if ($user !== null) {
            $user->removeRole($role);
        }

        $role->delete();
    }
};
