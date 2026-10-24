<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantBranding extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_branding';

    protected $fillable = ['display_name', 'phone', 'slogan', 'terms', 'primary_color', 'portal_url'];

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'display_name' => $this->display_name,
            'phone' => $this->phone,
            'slogan' => $this->slogan,
            'terms' => $this->terms,
            'primary_color' => $this->primary_color,
            'portal_url' => $this->portal_url,
            'has_logo' => $this->logo_path !== null,
        ];
    }
}
