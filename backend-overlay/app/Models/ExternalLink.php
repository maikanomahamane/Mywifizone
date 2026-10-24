<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Lien vers un service externe (ex. portail officiel Starlink). Aucun identifiant ni mot de passe n'est stocké. */
class ExternalLink extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['kind', 'name', 'url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function toPayload(): array
    {
        return ['id' => $this->getKey(), 'kind' => $this->kind, 'name' => $this->name, 'url' => $this->url, 'is_active' => $this->is_active];
    }
}
