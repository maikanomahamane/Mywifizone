<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SUPER_ADMIN = 'SUPER_ADMIN';

    public const OWNER = 'OWNER';

    public const AGENT = 'AGENT';

    protected $fillable = ['code', 'label'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }
}
