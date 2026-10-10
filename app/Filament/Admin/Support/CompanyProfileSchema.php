<?php

namespace App\Filament\Admin\Support;

use App\Models\Invoice;
use App\Models\OriginCountry;
use Filament\Forms;
use Filament\Schemas\Schema;

class CompanyProfileSchema
{
    public static function configure(Schema $schema, ?string $statePath = null, bool $forContact = false): Schema
    {
        $schema = $schema
            ->components(self::components($forContact))
            ->columns(2);

        if ($statePath !== null) {
            $schema->statePath($statePath);
        }

        return $schema;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    public static function components(bool $forContact = false): array
    {
        $components = [
            Forms\Components\TextInput::make('company_name')
                ->label('Company name')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('company_country')
                ->label('Company country')
                ->options(OriginCountry::options())
                ->searchable()
                ->preload()
                ->placeholder('Select country')
                ->default(null)
                ->nullable(),

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
        ];

        if ($forContact) {
            $components[] = Forms\Components\Select::make('default_finance_type')
                ->label('Default service category')
                ->options(fn (): array => Invoice::financeCategorySelectOptions())
                ->searchable()
                ->preload()
                ->native(false)
                ->nullable()
                ->helperText('Used as the Category when uploading an invoice for this contact.')
                ->columnSpanFull();

            $components[] = Forms\Components\TextInput::make('bank_account')
                ->label('Bank account (IBAN)')
                ->helperText('Used as the payee account for invoice bank payments (SEB).')
                ->maxLength(34)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null);

            $components[] = Forms\Components\TextInput::make('bank_bic')
                ->label('BIC')
                ->helperText('Optional. SEB Lithuania is often CBVILT2X.')
                ->maxLength(11)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null);
        } else {
            $components[] = Forms\Components\TextInput::make('company_iban')
                ->label('Company IBAN')
                ->helperText('Used as the payer account for SEPA salary exports (e.g. SEB).')
                ->maxLength(34)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null);

            $components[] = Forms\Components\TextInput::make('company_bic')
                ->label('Company BIC')
                ->helperText('Optional. SEB Lithuania is often CBVILT2X.')
                ->maxLength(11)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null);
        }

        $components = [
            ...$components,

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

        if ($forContact) {
            $components[] = Forms\Components\Toggle::make('private_provider')
                ->label('Private provider')
                ->helperText('Invoices for this contact are marked as Private provider and included in GPM312.')
                ->default(false)
                ->columnSpanFull();
        }

        return $components;
    }
}
