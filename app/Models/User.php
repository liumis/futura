<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AdminModule;
use App\Enums\NotificationType;
use App\Services\AdminHomepage;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use UnitEnum;

class User extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_PROJECT_MANAGER = 'project manager';

    /**
     * @return list<string>
     */
    public static function staffRoleNames(): array
    {
        return [
            self::ROLE_ADMIN,
            self::ROLE_MANAGER,
            self::ROLE_ACCOUNTANT,
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isStaff() || $this->isProjectManager(),
            'customer' => $this->hasRole(self::ROLE_CUSTOMER),
            default => false,
        };
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isManager(): bool
    {
        return $this->hasRole(self::ROLE_MANAGER);
    }

    public function isAccountant(): bool
    {
        return $this->hasRole(self::ROLE_ACCOUNTANT);
    }

    public function isProjectManager(): bool
    {
        return $this->hasRole(self::ROLE_PROJECT_MANAGER);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(self::staffRoleNames());
    }

    /**
     * Additional admin modules assigned to a project manager (Tasks is always included).
     *
     * @return list<string>
     */
    public function assignedModuleValues(): array
    {
        $values = [];

        foreach ((array) ($this->modules ?? []) as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (AdminModule::tryFrom($value) === null) {
                continue;
            }

            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    public function canAccessModule(AdminModule|string $module): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        if (! $this->isProjectManager()) {
            return false;
        }

        $resolved = $module instanceof AdminModule
            ? $module
            : AdminModule::tryFrom($module);

        if ($resolved === null) {
            return false;
        }

        if ($resolved === AdminModule::defaultForProjectManager()) {
            return true;
        }

        return in_array($resolved->value, $this->assignedModuleValues(), true);
    }

    public function homepageUrl(): string
    {
        return AdminHomepage::urlFor($this);
    }

    /**
     * @return array<string, string>
     */
    public function homepageModuleOptions(): array
    {
        return AdminHomepage::optionsFor($this);
    }

    public function canAccessNavigationGroup(null|string|UnitEnum $group): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        $module = AdminModule::tryFromNavigationGroup($group);

        return $module !== null && $this->canAccessModule($module);
    }

    public function isLastAdmin(): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        return ! static::query()
            ->role(self::ROLE_ADMIN)
            ->whereKeyNot($this->getKey())
            ->exists();
    }

    /**
     * Roles the authenticated actor may assign when creating/editing users.
     *
     * @return list<string>
     */
    public function assignableRoleNames(): array
    {
        if ($this->isAdmin()) {
            return [
                self::ROLE_ADMIN,
                self::ROLE_MANAGER,
                self::ROLE_ACCOUNTANT,
                self::ROLE_EMPLOYEE,
                self::ROLE_CUSTOMER,
                self::ROLE_PROJECT_MANAGER,
            ];
        }

        if ($this->isManager()) {
            return [
                self::ROLE_MANAGER,
                self::ROLE_ACCOUNTANT,
                self::ROLE_EMPLOYEE,
                self::ROLE_CUSTOMER,
                self::ROLE_PROJECT_MANAGER,
            ];
        }

        if ($this->isAccountant()) {
            return [
                self::ROLE_ACCOUNTANT,
                self::ROLE_EMPLOYEE,
                self::ROLE_CUSTOMER,
                self::ROLE_PROJECT_MANAGER,
            ];
        }

        return [];
    }

    public function canManageUsers(): bool
    {
        return $this->isStaff();
    }

    public function canEditUser(self $target): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isManager()) {
            return ! $target->isAdmin();
        }

        if ($this->isAccountant()) {
            return ! $target->hasAnyRole([self::ROLE_ADMIN, self::ROLE_MANAGER]);
        }

        return false;
    }

    public function canDeleteUser(self $target): bool
    {
        if ($target->isLastAdmin()) {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isManager()) {
            return ! $target->isAdmin();
        }

        if ($this->isAccountant()) {
            return ! $target->hasAnyRole([self::ROLE_ADMIN, self::ROLE_MANAGER]);
        }

        return false;
    }

    /**
     * @param  list<int|string>  $roleIdsOrNames
     * @return list<int>
     */
    public function filterAssignableRoleIds(array $roleIdsOrNames): array
    {
        $assignable = $this->assignableRoleNames();
        if ($assignable === [] || $roleIdsOrNames === []) {
            return [];
        }

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where(function ($query) use ($roleIdsOrNames): void {
                $ids = [];
                $names = [];
                foreach ($roleIdsOrNames as $value) {
                    if (is_numeric($value)) {
                        $ids[] = (int) $value;
                    } elseif (is_string($value) && $value !== '') {
                        $names[] = $value;
                    }
                }

                if ($ids !== []) {
                    $query->orWhereIn('id', $ids);
                }
                if ($names !== []) {
                    $query->orWhereIn('name', $names);
                }
            })
            ->get(['id', 'name']);

        return $roles
            ->filter(fn (Role $role): bool => in_array($role->name, $assignable, true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function getFilamentName(): string
    {
        return $this->displayName();
    }

    public function fullName(): string
    {
        return trim(($this->name ?? '').' '.($this->surname ?? ''));
    }

    public function displayName(): string
    {
        $fullName = $this->fullName();

        return $fullName !== '' ? $fullName : (string) ($this->email ?? '');
    }

    /**
     * @return array<int, string>
     */
    public static function optionsByFullName(): array
    {
        return static::query()
            ->orderBy('name')
            ->orderBy('surname')
            ->get(['id', 'name', 'surname', 'email'])
            ->mapWithKeys(fn (self $user): array => [
                $user->getKey() => $user->displayName(),
            ])
            ->all();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'email',
        'phone',
        'password',
        'customer_level_id',
        'vat_rate_id',
        'invoice_language',
        'payment_term',
        'company_name',
        'company_country',
        'export',
        'company_address',
        'company_shipping_address',
        'company_code',
        'company_vat',
        'notification_types',
        'modules',
        'homepage_module',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'export' => 'boolean',
            'payment_term' => 'integer',
            'notification_types' => 'array',
            'modules' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function wantsNotification(NotificationType $type): bool
    {
        return in_array($type->value, (array) ($this->notification_types ?? []), true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customerLevel(): BelongsTo
    {
        return $this->belongsTo(CustomerLevel::class);
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function documentConfirmations(): HasMany
    {
        return $this->hasMany(DocumentConfirmation::class);
    }

    /**
     * Lithuania domestic customers (export=false, or country/VAT LT when export unset).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLithuaniaMarket($query)
    {
        return $query->where(function ($builder): void {
            $builder
                ->where('export', false)
                ->orWhere(function ($fallback): void {
                    $fallback
                        ->whereNull('export')
                        ->where(function ($geo): void {
                            $geo
                                ->whereRaw('LOWER(TRIM(COALESCE(company_country, ""))) in (?, ?, ?)', ['lithuania', 'lietuva', 'lt'])
                                ->orWhereRaw("UPPER(REPLACE(COALESCE(company_vat, ''), ' ', '')) like 'LT%'");
                        });
                });
        });
    }

    /**
     * Export / abroad customers (export=true, or non-LT country when export unset).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeExportMarket($query)
    {
        return $query->where(function ($builder): void {
            $builder
                ->where('export', true)
                ->orWhere(function ($fallback): void {
                    $fallback
                        ->whereNull('export')
                        ->whereRaw('LOWER(TRIM(COALESCE(company_country, ""))) not in (?, ?, ?)', ['lithuania', 'lietuva', 'lt'])
                        ->whereRaw("UPPER(REPLACE(COALESCE(company_vat, ''), ' ', '')) not like 'LT%'");
                });
        });
    }

    /**
     * @param  array<int|string>|null  $rolesState  Role ids from Filament, or legacy name strings
     */
    public static function formStateIncludesCustomerRole(?array $rolesState): bool
    {
        return self::formStateIncludesRole($rolesState, self::ROLE_CUSTOMER);
    }

    /**
     * @param  array<int|string>|null  $rolesState  Role ids from Filament, or legacy name strings
     */
    public static function formStateIncludesProjectManagerRole(?array $rolesState): bool
    {
        return self::formStateIncludesRole($rolesState, self::ROLE_PROJECT_MANAGER);
    }

    /**
     * @param  array<int|string>|null  $rolesState  Role ids from Filament, or legacy name strings
     */
    public static function formStateIncludesRole(?array $rolesState, string $roleName): bool
    {
        if ($rolesState === null || $rolesState === []) {
            return false;
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->first();

        if ($role === null) {
            return false;
        }

        foreach ($rolesState as $value) {
            if (is_string($value) && $value === $roleName) {
                return true;
            }
            if ((int) $value === (int) $role->getKey()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $modules
     * @return list<string>|null
     */
    public static function normalizeAssignableModules(?array $modules): ?array
    {
        if ($modules === null || $modules === []) {
            return null;
        }

        $allowed = array_keys(AdminModule::additionalOptionsForProjectManager());
        $normalized = [];

        foreach ($modules as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (! in_array($value, $allowed, true)) {
                continue;
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? null : $normalized;
    }
}
