<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['roles'])) {
            $data['roles'] = ['customer'];
        }

        $rolesState = is_array($data['roles'] ?? null) ? $data['roles'] : [];

        if (! User::formStateIncludesCustomerRole($rolesState)) {
            $data['customer_level_id'] = null;
            foreach ([
                'company_name',
                'company_country',
                'company_address',
                'company_shipping_address',
                'company_code',
                'company_vat',
            ] as $field) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
