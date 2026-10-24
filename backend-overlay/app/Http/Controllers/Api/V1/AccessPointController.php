<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AccessPointController extends Controller
{
    public function __construct(
        private readonly PlanEntitlements $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', AccessPoint::class);

        $filters = $request->validate(['router_id' => ['nullable', 'uuid']]);

        $page = AccessPoint::query()
            ->when($filters['router_id'] ?? null, fn ($q, $v) => $q->where('router_id', $v))
            ->orderBy('name')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (AccessPoint $a) => $a->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $ap = AccessPoint::findOrFail($id);
        Gate::authorize('view', $ap);

        return ApiResponse::ok($ap->toPayload());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', AccessPoint::class);

        $data = $request->validate([
            'router_id' => ['required', 'uuid', Rules::ownedBy(Router::class)],
            'name' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        $ap = DB::transaction(function () use ($data) {
            Subscription::lockForUpdate()->first();
            $this->entitlements->assertCanAdd('max_access_points', AccessPoint::count());

            return AccessPoint::create($data);
        });

        $this->audit->log('access_point.created', null, $ap);

        return ApiResponse::ok($ap->toPayload(), status: 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $ap = AccessPoint::findOrFail($id);
        Gate::authorize('update', $ap);

        $ap->update($request->validate([
            'router_id' => ['sometimes', 'required', 'uuid', Rules::ownedBy(Router::class)],
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'location' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]));

        $this->audit->log('access_point.updated', null, $ap);

        return ApiResponse::ok($ap->toPayload());
    }

    public function destroy(string $id): JsonResponse
    {
        $ap = AccessPoint::findOrFail($id);
        Gate::authorize('delete', $ap);

        $ap->delete();
        $this->audit->log('access_point.deleted', null, $ap);

        return ApiResponse::ok(null);
    }
}
