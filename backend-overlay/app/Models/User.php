<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Note : User n'utilise PAS BelongsToTenant. La résolution à la connexion se fait avant
 * tout contexte tenant ; les listings (agents...) devront filtrer explicitement par tenant_id.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    protected $attributes = ['status' => 'ACTIVE'];

    protected $fillable = ['name', 'email', 'phone', 'password', 'locale'];

    protected $hidden = ['password'];

    /** @var array<int, string>|null */
    private ?array $permissionCodes = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->code === Role::SUPER_ADMIN;
    }

    /** @return array<int, string> */
    public function permissionCodes(): array
    {
        return $this->permissionCodes ??= $this->role
            ? $this->role->permissions()->pluck('code')->all()
            : [];
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    /** Normalise un téléphone : on ne garde que "+" initial et les chiffres. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $clean = preg_replace('/[^\d+]/', '', $phone) ?? '';

        return $clean === '' ? null : $clean;
    }
}
