<?php

namespace App\Filament\Admin\Resources\Projects;

use App\Filament\Admin\Resources\Projects\Pages\CreateProject;
use App\Filament\Admin\Resources\Projects\Pages\EditProject;
use App\Filament\Admin\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\ProjectStage;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Projects';

    protected static ?string $modelLabel = 'Project';

    protected static ?string $pluralModelLabel = 'Projects';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->alphaDash(),

                Forms\Components\Select::make('project_type_id')
                    ->label('Project type')
                    ->relationship(
                        'projectType',
                        'name',
                        fn (Builder $query) => $query->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(fn (Set $set): mixed => $set('project_stage_id', null)),

                Forms\Components\Select::make('project_stage_id')
                    ->label('Project stage')
                    ->options(function (Get $get): array {
                        $typeId = $get('project_type_id');

                        if (blank($typeId)) {
                            return [];
                        }

                        return ProjectStage::query()
                            ->where('project_type_id', $typeId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->disabled(fn (Get $get): bool => blank($get('project_type_id')))
                    ->dehydrated()
                    ->dehydrateStateUsing(function (mixed $state, Get $get): mixed {
                        $typeId = $get('project_type_id');

                        if (blank($state) || blank($typeId)) {
                            return null;
                        }

                        $belongsToType = ProjectStage::query()
                            ->whereKey($state)
                            ->where('project_type_id', $typeId)
                            ->exists();

                        return $belongsToType ? $state : null;
                    })
                    ->helperText('Stages available for the selected project type.'),

                Forms\Components\Toggle::make('archived')
                    ->label('Archived')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('projectType.name')
                    ->label('Project type')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('projectStage.name')
                    ->label('Project stage')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('archived')
                    ->label('Archived')
                    ->onColor('warning')
                    ->offColor('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('project_type_id')
                    ->label('Project type')
                    ->relationship('projectType', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('project_stage_id')
                    ->label('Project stage')
                    ->relationship('projectStage', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                SelectFilter::make('archived')
                    ->label('Status')
                    ->options([
                        'active' => 'Active only',
                        'archived' => 'Archived only',
                        'all' => 'All projects',
                    ])
                    ->default('active')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = filled($data['value'] ?? null) ? (string) $data['value'] : 'active';

                        if ($value === 'active') {
                            $query->where('archived', false);
                        } elseif ($value === 'archived') {
                            $query->where('archived', true);
                        }
                    }),
            ])
            ->recordActions([
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
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProjects::route('/'),
            'create' => CreateProject::route('/create'),
            'edit' => EditProject::route('/{record}/edit'),
        ];
    }
}
