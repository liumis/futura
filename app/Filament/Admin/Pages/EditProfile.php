<?php

namespace App\Filament\Admin\Pages;

use App\Models\OriginCountry;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public static function getLabel(): string
    {
        return 'Edit profile';
    }

    public function form(Schema $schema): Schema
    {
        $isCustomer = fn (): bool => auth()->user()?->hasRole('customer') ?? false;

        return $schema
            ->components([
                $this->getNameFormComponent()
                    ->label('First name'),

                Forms\Components\TextInput::make('surname')
                    ->maxLength(255)
                    ->label('Surname')
                    ->required(),

                $this->getEmailFormComponent(),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(50)
                    ->label('Phone'),

                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),

                Forms\Components\Select::make('customer_level_id')
                    ->relationship('customerLevel', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Customer level')
                    ->placeholder('Select level')
                    ->visible($isCustomer)
                    ->disabled()
                    ->dehydrated(false),

                Section::make('Company')
                    ->description('Optional company details')
                    ->visible($isCustomer)
                    ->schema([
                        Forms\Components\TextInput::make('company_name')
                            ->maxLength(255)
                            ->label('Company name')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('company_country')
                            ->label('Company country')
                            ->options(OriginCountry::options())
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('company_address')
                            ->rows(3)
                            ->maxLength(2000)
                            ->label('Company address')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('company_shipping_address')
                            ->rows(3)
                            ->maxLength(2000)
                            ->label('Company shipping address')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('company_code')
                            ->maxLength(100)
                            ->label('Company code'),

                        Forms\Components\TextInput::make('company_vat')
                            ->maxLength(100)
                            ->label('Company VAT'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! auth()->user()?->hasRole('customer')) {
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

        return parent::mutateFormDataBeforeSave($data);
    }
}
