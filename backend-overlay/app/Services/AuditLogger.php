<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata  ne jamais y mettre de mot de passe ni de jeton
     */
    public function log(string $action, ?User $actor = null, ?Model $subject = null, array $metadata = [], ?string $tenantId = null): void
    {
        $actor ??= $this->request->user();

        AuditLog::create([
            'tenant_id' => $tenantId ?? $actor?->tenant_id,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255),
            'request_id' => $this->request->attributes->get('request_id'),
            'metadata' => $metadata ?: null,
        ]);
    }
}
