<?php

namespace App\Filament\Admin\Support;

use App\Models\OriginCountry;
use Filament\Forms;
use Filament\Schemas\Schema;

class CompanyProfileSchema
{
    public static function configure(Schema $schema, ?string $statePath = null): Schema
    {
        $schema = $schema
            ->components(self::components())
            ->columns(2);

        if ($statePath !== null) {
            $schema->statePath($statePath);
        }

        return $schema;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function components(): array
    {
        return [
            Forms\Components\TextInput::make('company_name')
                ->label('Company name')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('company_country')
                ->label('Company country')
                ->options(OriginCountry::options())
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\TextInput::make('company_id')
                ->label('Company id')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('company_vat')
                ->label('Company VAT')
                ->maxLength(255),

            Forms\Components\Textarea::make('company_address')
                ->label('Company address')
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            Forms\Components\TextInput::make('company_email')
                ->label('Company email')
                ->email()
                ->maxLength(255),

            Forms\Components\TextInput::make('company_phone')
                ->label('Company phone')
                ->tel()
                ->maxLength(255),

            Forms\Components\TextInput::make('company_iban')
                ->label('Company IBAN')
                ->helperText('Used as the payer account for SEPA salary exports (e.g. SEB).')
                ->maxLength(34)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null),

            Forms\Components\TextInput::make('company_bic')
                ->label('Company BIC')
                ->helperText('Optional. SEB Lithuania is often CBVILT2X.')
                ->maxLength(11)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null),

            Forms\Components\TextInput::make('contact_name')
                ->label('Contact name')
                ->maxLength(255),

            Forms\Components\TextInput::make('contact_email')
                ->label('Contact email')
                ->email()
                ->maxLength(255),

            Forms\Components\TextInput::make('contact_phone')
                ->label('Contact phone')
                ->tel()
                ->maxLength(255),
        ];
    }
}
