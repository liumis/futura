<?php

namespace Tests\Feature;

use App\Enums\AdminModule;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            User::ROLE_ADMIN,
            User::ROLE_MANAGER,
            User::ROLE_ACCOUNTANT,
            User::ROLE_EMPLOYEE,
            User::ROLE_CUSTOMER,
            User::ROLE_PROJECT_MANAGER,
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staff_roles_can_access_admin_panel(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_MANAGER, User::ROLE_ACCOUNTANT] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        }

        $customer = User::factory()->create();
        $customer->assignRole(User::ROLE_CUSTOMER);
        $this->assertFalse($customer->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_user_deletion_is_soft_delete_and_last_admin_cannot_be_deleted(): void
    {
        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-main@example.com');
        $otherAdmin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-two@example.com');
        $manager = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager@example.com');

        $this->assertTrue($admin->canDeleteUser($otherAdmin));
        $this->assertTrue($admin->canDeleteUser($manager));

        $otherAdmin->delete();
        $this->assertSoftDeleted('users', ['id' => $otherAdmin->getKey()]);

        $this->assertTrue($admin->fresh()->isLastAdmin());
        $this->assertFalse($admin->canDeleteUser($admin->fresh()));
        $this->assertFalse(UserResource::canDelete($admin->fresh()));
    }

    public function test_manager_cannot_edit_or_delete_admins_but_can_assign_manager(): void
    {
        $manager = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager-actor@example.com');
        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-target@example.com');
        $peer = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager-peer@example.com');

        $this->assertFalse($manager->canEditUser($admin));
        $this->assertFalse($manager->canDeleteUser($admin));
        $this->assertTrue($manager->canEditUser($peer));
        $this->assertTrue($manager->canDeleteUser($peer));
        $this->assertContains(User::ROLE_MANAGER, $manager->assignableRoleNames());
        $this->assertNotContains(User::ROLE_ADMIN, $manager->assignableRoleNames());
    }

    public function test_accountant_cannot_delete_admin_or_manager(): void
    {
        $accountant = $this->makeUserWithRole(User::ROLE_ACCOUNTANT, 'accountant@example.com');
        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-acc@example.com');
        $manager = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager-acc@example.com');
        $customer = $this->makeUserWithRole(User::ROLE_CUSTOMER, 'customer-acc@example.com');

        $this->assertFalse($accountant->canDeleteUser($admin));
        $this->assertFalse($accountant->canDeleteUser($manager));
        $this->assertFalse($accountant->canEditUser($admin));
        $this->assertFalse($accountant->canEditUser($manager));
        $this->assertTrue($accountant->canDeleteUser($customer));
        $this->assertNotContains(User::ROLE_ADMIN, $accountant->assignableRoleNames());
        $this->assertNotContains(User::ROLE_MANAGER, $accountant->assignableRoleNames());
    }

    public function test_admin_can_create_admin_users_and_manager_cannot(): void
    {
        $adminRoleId = (int) Role::findByName(User::ROLE_ADMIN, 'web')->getKey();
        $managerRoleId = (int) Role::findByName(User::ROLE_MANAGER, 'web')->getKey();

        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-create@example.com');
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New',
                'surname' => 'Admin',
                'email' => 'new-admin@example.com',
                'password' => 'password',
                'roles' => [$adminRoleId],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdAdmin = User::query()->where('email', 'new-admin@example.com')->first();
        $this->assertNotNull($createdAdmin);
        $this->assertTrue($createdAdmin->isAdmin());

        $manager = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager-create@example.com');
        $this->actingAs($manager);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Blocked',
                'surname' => 'Admin',
                'email' => 'blocked-admin@example.com',
                'password' => 'password',
                'roles' => [$adminRoleId],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles.0']);

        $this->assertNull(User::query()->where('email', 'blocked-admin@example.com')->first());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New',
                'surname' => 'Manager',
                'email' => 'new-manager@example.com',
                'password' => 'password',
                'roles' => [$managerRoleId],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'new-manager@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->isManager());
        $this->assertFalse($created->isAdmin());
    }

    public function test_manager_cannot_open_admin_edit_page(): void
    {
        $manager = $this->makeUserWithRole(User::ROLE_MANAGER, 'manager-edit@example.com');
        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-edit@example.com');
        $this->actingAs($manager);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->assertForbidden();
    }

    public function test_project_manager_has_tasks_by_default_and_extra_modules(): void
    {
        $pm = $this->makeUserWithRole(User::ROLE_PROJECT_MANAGER, 'pm@example.com');
        $pm->forceFill(['modules' => null])->save();

        $this->assertTrue($pm->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($pm->canAccessModule(AdminModule::Tasks));
        $this->assertTrue($pm->canAccessNavigationGroup('Tasks'));
        $this->assertFalse($pm->canAccessModule(AdminModule::Finances));
        $this->assertFalse($pm->canAccessModule(AdminModule::Documents));
        $this->assertFalse($pm->canAccessModule(AdminModule::Orders));

        $this->actingAs($pm);
        $this->assertFalse(UserResource::canViewAny());
        $this->assertTrue(TaskResource::canViewAny());
        $this->assertFalse(OrderResource::canViewAny());
        $this->assertFalse(DocumentResource::canViewAny());

        $pm->forceFill([
            'modules' => [AdminModule::Finances->value, AdminModule::Documents->value],
        ])->save();

        $pm = $pm->fresh();
        $this->assertTrue($pm->canAccessModule(AdminModule::Tasks));
        $this->assertTrue($pm->canAccessModule(AdminModule::Finances));
        $this->assertTrue($pm->canAccessModule(AdminModule::Documents));
        $this->assertFalse($pm->canAccessModule(AdminModule::Orders));

        $this->actingAs($pm);
        $this->assertTrue(DocumentResource::canViewAny());
        $this->assertFalse(OrderResource::canViewAny());
    }

    public function test_admin_can_assign_additional_modules_to_project_manager(): void
    {
        $pmRoleId = (int) Role::findByName(User::ROLE_PROJECT_MANAGER, 'web')->getKey();
        $admin = $this->makeUserWithRole(User::ROLE_ADMIN, 'admin-pm-modules@example.com');
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Pat',
                'surname' => 'Manager',
                'email' => 'pm-modules@example.com',
                'password' => 'password',
                'roles' => [$pmRoleId],
                'modules' => [AdminModule::Finances->value, AdminModule::Documents->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'pm-modules@example.com')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->isProjectManager());
        $this->assertTrue($created->canAccessModule(AdminModule::Tasks));
        $this->assertTrue($created->canAccessModule(AdminModule::Finances));
        $this->assertTrue($created->canAccessModule(AdminModule::Documents));
        $this->assertSame(
            [AdminModule::Finances->value, AdminModule::Documents->value],
            $created->assignedModuleValues()
        );
    }

    private function makeUserWithRole(string $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role);

        return $user;
    }
}
