<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Pages\EditRecord;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Livewire form state (statePath: data) — keep name fields even if dehydrate skips them.
        $formState = is_array($this->data ?? null) ? $this->data : [];

        if (array_key_exists('name', $formState)) {
            $data['name'] = $formState['name'];
        }

        if (array_key_exists('surname', $formState)) {
            $data['surname'] = $formState['surname'];
        }

        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        $rolesState = $formState['roles'] ?? ($data['roles'] ?? []);
        $rolesState = is_array($rolesState) ? $rolesState : [];

        $actor = auth()->user();
        if ($actor instanceof User) {
            $rolesState = $actor->filterAssignableRoleIds($rolesState);
        }

        $this->pendingRoleIds = $rolesState;
        $this->data['roles'] = $rolesState;
        unset($data['roles']);

        if (User::formStateIncludesProjectManagerRole($rolesState)) {
            $data['modules'] = User::normalizeAssignableModules(
                is_array($formState['modules'] ?? null)
                    ? $formState['modules']
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
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $record->fill($data);
        $record->save();

        return $record->refresh();
    }

    protected function afterSave(): void
    {
        if ($this->pendingRoleIds === null || ! $this->record instanceof User) {
            return;
        }

        $this->record->roles()->sync($this->pendingRoleIds);
    }
}
