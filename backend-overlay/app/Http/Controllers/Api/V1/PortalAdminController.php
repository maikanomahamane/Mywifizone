<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Portal\PortalOrderStatus;
use App\Domain\Portal\PortalSlug;
use App\Http\Controllers\Controller;
use App\Models\PortalOrder;
use App\Services\AuditLogger;
use App\Services\BrandingService;
use App\Services\PlanEntitlements;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Réglages du portail (propriétaire) et suivi des commandes d'achat direct. */
class PortalAdminController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->payload($request, $this->branding->current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'regenerate_slug' => ['sometimes', 'boolean']]);
        if (! empty($data['enabled'])) {
            $this->entitlements->assertFeature('captive_portal');
        }

        $b = $this->branding->current();
        if (array_key_exists('enabled', $data)) {
            $b->portal_enabled = (bool) $data['enabled'];
        }
        if ($b->portal_slug === null || ! empty($data['regenerate_slug'])) {
            $b->portal_slug = $this->uniqueSlug();
        }
        $b->save();
        $this->audit->log('portal.settings_updated', null, $b, ['enabled' => $b->portal_enabled, 'regenerated' => ! empty($data['regenerate_slug'])]);

        return ApiResponse::ok($this->payload($request, $b->refresh()));
    }

    public function orders(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(PortalOrderStatus::ALL)]]);
        $page = PortalOrder::with('profile')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (PortalOrder $o) => $o->toOwnerPayload());
    }

    private function uniqueSlug(): string
    {
        do {
            $slug = PortalSlug::generate();
        } while (\App\Models\TenantBranding::withoutGlobalScopes()->where('portal_slug', $slug)->exists());

        return $slug;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, \App\Models\TenantBranding $b): array
    {
        $page = $b->portal_slug ? $request->getSchemeAndHttpHost().'/api/v1/portal/'.$b->portal_slug.'/page' : null;

        return [
            'enabled' => (bool) $b->portal_enabled,
            'slug' => $b->portal_slug,
            'page_url' => $page,
            'feature_available' => $this->entitlements->hasFeature('captive_portal'),
        ];
    }
}
