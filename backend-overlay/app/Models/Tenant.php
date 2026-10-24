<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    protected $attributes = ['status' => 'ACTIVE'];

    protected $fillable = ['name', 'country', 'locale', 'currency', 'timezone', 'status'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Abonnement courant (une seule ligne par tenant). Hors scope tenant : on filtre par la clé étrangère. */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->withoutGlobalScopes();
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
