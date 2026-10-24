<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    // tenant_id volontairement absent de $fillable (jamais depuis la requête)
    protected $fillable = ['name', 'address'];
}
