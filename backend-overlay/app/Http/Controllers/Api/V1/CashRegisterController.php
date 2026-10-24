<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CashRegisterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly PlanEntitlements $entitlements) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CashRegister::class);

        $page = CashRegister::query()->orderBy('name')->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (CashRegister $r) => $r->toPayload());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', CashRegister::class);
        $this->entitlements->assertFeature('cash');

        $request->merge(['currency' => strtoupper((string) $request->input('currency'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'currency' => ['required', 'string', Rule::exists('currencies', 'code')],
            'site_id' => ['nullable', 'uuid', Rules::ownedBy(Site::class)],
        ]);

        $register = CashRegister::create($data + ['is_active' => true]);
        $this->audit->log('cash_register.created', null, $register, ['currency' => $register->currency]);

        return ApiResponse::ok($register->toPayload(), status: 201);
    }

    /** La devise d'une caisse ne change pas (les sessions et écritures existantes y sont attachées). */
    public function update(Request $request, string $id): JsonResponse
    {
        $register = CashRegister::findOrFail($id);
        Gate::authorize('update', $register);

        $register->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'site_id' => ['sometimes', 'nullable', 'uuid', Rules::ownedBy(Site::class)],
        ]));
        $this->audit->log('cash_register.updated', null, $register);

        return ApiResponse::ok($register->toPayload());
    }
}
