<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Models\WriteOffSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WriteOffOptions extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Write-off options';

    protected static string|UnitEnum|null $navigationGroup = 'Financial options';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Write-off options';

    protected string $view = 'filament.admin.pages.write-off-options';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = WriteOffSetting::instance()->load('signatories');

        $this->form->fill([
            'signatory_user_ids' => self::signatoryUsersQuery()
                ->whereIn('id', $settings->signatories->pluck('id'))
                ->pluck('id')
                ->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Signing / confirmation')
                    ->description('Select users who may sign or confirm write-off documents.')
                    ->schema([
                        Select::make('signatory_user_ids')
                            ->label('Signatory users')
                            ->helperText('These users will be available for the write-off signing and confirmation process.')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => self::signatoryUserOptions()),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->color('primary')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = WriteOffSetting::instance();

        $settings->syncSignatories(
            self::signatoryUsersQuery()
                ->whereIn('id', $state['signatory_user_ids'] ?? [])
                ->pluck('id')
                ->all(),
        );

        Notification::make()
            ->title('Write-off options saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function userLabel(User $user): string
    {
        $name = trim(((string) ($user->name ?? '')).' '.((string) ($user->surname ?? '')));

        if ($name !== '') {
            return filled($user->email)
                ? $name.' ('.$user->email.')'
                : $name;
        }

        return (string) ($user->email ?? 'User #'.$user->id);
    }

    /**
     * @return array<int, string>
     */
    public static function signatoryUserOptions(): array
    {
        return self::signatoryUsersQuery()
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->id => self::userLabel($user),
            ])
            ->all();
    }

    public static function signatoryUsersQuery(): Builder
    {
        return User::query()
            ->whereDoesntHave(
                'roles',
                fn (Builder $query): Builder => $query->where('name', 'customer'),
            )
            ->orderBy('name')
            ->orderBy('surname');
    }
}
