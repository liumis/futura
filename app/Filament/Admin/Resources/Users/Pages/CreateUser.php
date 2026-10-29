<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\AdminHomepage;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @var list<int>|null
     */
    protected ?array $pendingRoleIds = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        $rolesState = is_array($this->data['roles'] ?? null)
            ? $this->data['roles']
            : (is_array($data['roles'] ?? null) ? $data['roles'] : []);

        if ($rolesState === []) {
            $customerRoleId = Role::query()
                ->where('name', User::ROLE_CUSTOMER)
                ->where('guard_name', 'web')
                ->value('id');

            $rolesState = $customerRoleId ? [(int) $customerRoleId] : [];
        }

        if ($actor instanceof User) {
            $rolesState = $actor->filterAssignableRoleIds($rolesState);
        }

        $this->pendingRoleIds = $rolesState;
        $this->data['roles'] = $rolesState;
        unset($data['roles']);

        if (User::formStateIncludesProjectManagerRole($rolesState)) {
            $data['modules'] = User::normalizeAssignableModules(
                is_array($this->data['modules'] ?? null)
                    ? $this->data['modules']
                    : (is_array($data['modules'] ?? null) ? $data['modules'] : null)
            );
        } else {
            $data['modules'] = null;
        }

        if (! User::formStateIncludesCustomerRole($rolesState)) {
            $data['customer_level_id'] = null;
            foreach ([
                'company_name',
                'company_country',
                'company_address',
                'company_shipping_address',
                'company_code',
                'company_vat',
                'payment_term',
            ] as $field) {
                $data[$field] = null;
            }

            $data['homepage_module'] = AdminHomepage::normalize(
                isset($this->data['homepage_module'])
                    ? (string) $this->data['homepage_module']
                    : (isset($data['homepage_module']) ? (string) $data['homepage_module'] : null)
            );
        } else {
            $data['homepage_module'] = null;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->pendingRoleIds === null || ! $this->record instanceof User) {
            return;
        }

        $this->record->roles()->sync($this->pendingRoleIds);
    }
}
