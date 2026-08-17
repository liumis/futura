<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->hasRole('admin'),
            'customer' => $this->hasRole('customer'),
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        $fullName = $this->fullName();

        return $fullName !== '' ? $fullName : (string) ($this->email ?? '');
    }

    public function fullName(): string
    {
        return trim(($this->name ?? '').' '.($this->surname ?? ''));
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
        'company_name',
        'company_country',
        'export',
        'company_address',
        'company_shipping_address',
        'company_code',
        'company_vat',
        'notification_types',
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
            'notification_types' => 'array',
        ];
    }

    public function wantsNotification(\App\Enums\NotificationType $type): bool
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

    /**
     * @param  array<int|string>|null  $rolesState  Role ids from Filament, or legacy name strings
     */
    public static function formStateIncludesCustomerRole(?array $rolesState): bool
    {
        if ($rolesState === null || $rolesState === []) {
            return false;
        }

        $customerRole = Role::query()
            ->where('name', 'customer')
            ->where('guard_name', 'web')
            ->first();

        if ($customerRole === null) {
            return false;
        }

        foreach ($rolesState as $value) {
            if (is_string($value) && $value === 'customer') {
                return true;
            }
            if ((int) $value === (int) $customerRole->getKey()) {
                return true;
            }
        }

        return false;
    }
}
