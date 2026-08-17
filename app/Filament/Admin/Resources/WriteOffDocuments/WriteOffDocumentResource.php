<?php

namespace App\Filament\Admin\Resources\WriteOffDocuments;

use App\Filament\Admin\Resources\WriteOffDocuments\Pages\ListWriteOffDocuments;
use App\Models\WriteOffDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class WriteOffDocumentResource extends Resource
{
    protected static ?string $model = WriteOffDocument::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Write-off documents';

    protected static ?string $modelLabel = 'write-off document';

    protected static ?string $pluralModelLabel = 'Write-off documents';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_number')
                    ->label('Document no.')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('document_date')
                    ->label('Document date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_manual_updates_count')
                    ->label('Lines')
                    ->counts('stockManualUpdates')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Created by')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('document_links')
                    ->label('Document')
                    ->formatStateUsing(function (WriteOffDocument $record): HtmlString {
                        $ltUrl = e(route('write-off-documents.file', ['writeOffDocument' => $record, 'lang' => 'lt']));
                        $enUrl = e(route('write-off-documents.file', ['writeOffDocument' => $record, 'lang' => 'en']));

                        return new HtmlString(
                            '<span class="whitespace-nowrap">'
                            .'<a href="'.$ltUrl.'" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">LT</a>'
                            .' / '
                            .'<a href="'.$enUrl.'" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">EN</a>'
                            .'</span>'
                        );
                    })
                    ->html(),
            ])
            ->defaultSort('document_date', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWriteOffDocuments::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('stockManualUpdates')->with('user');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
