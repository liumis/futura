<?php

namespace App\Filament\Admin\Resources\MailTemplates;

use App\Filament\Admin\Resources\MailTemplates\Pages;
use App\Models\MailTemplate;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use UnitEnum;

class MailTemplateResource extends Resource
{
    protected static ?string $model = MailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Mail templates';

    protected static ?string $modelLabel = 'Mail template';

    protected static ?string $pluralModelLabel = 'Mail templates';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('subject')
                    ->label('Subject')
                    ->maxLength(255)
                    ->helperText('Use {order_id} for the warehouse order number.'),

                Forms\Components\TextInput::make('from_name')
                    ->label('From name')
                    ->maxLength(255)
                    ->helperText('Prepended to the sender name as: From name | Your name. The from email is your logged-in user email.'),

                Forms\Components\Textarea::make('text')
                    ->label('Text')
                    ->rows(12)
                    ->columnSpanFull(),
            ]);
    }

    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading('Email template preview')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (MailTemplate $record): Htmlable => new HtmlString(
                view('filament.admin.components.mail-template-preview', [
                    'subject' => str_replace('{order_id}', '123', trim((string) $record->subject)),
                    'fromName' => self::previewFromName($record),
                    'body' => trim((string) $record->text),
                ])->render()
            ));
    }

    public static function previewInBrowserAction(): Action
    {
        return Action::make('previewInBrowser')
            ->label('Open in browser')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (MailTemplate $record): string => URL::temporarySignedRoute(
                'mail-templates.preview',
                now()->addMinutes(30),
                ['mailTemplate' => $record],
            ))
            ->openUrlInNewTab();
    }

    protected static function previewFromName(MailTemplate $record): string
    {
        $user = auth()->user();
        $userName = $user instanceof User
            ? (trim(implode(' ', array_filter([$user->name, $user->surname]))) ?: (string) $user->email)
            : 'Your name';

        $templateFromName = trim((string) ($record->from_name ?? ''));

        return $templateFromName !== ''
            ? $templateFromName.' | '.$userName
            : $userName;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subject')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('from_name')
                    ->label('From name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('text')
                    ->label('Text')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                self::previewAction(),
                self::previewInBrowserAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailTemplates::route('/'),
            'create' => Pages\CreateMailTemplate::route('/create'),
            'edit' => Pages\EditMailTemplate::route('/{record}/edit'),
        ];
    }
}
