<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanEntitlements;
use App\Services\TokenService;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Agents (vendeurs) du tenant. User n'est pas sous scope global : tout est filtré explicitement par tenant_id. */
class StaffController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlanEntitlements $entitlements,
        private readonly TokenService $tokens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('staff.view-any');

        $page = $this->agents($request)->orderBy('name')->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (User $u) => $this->payload($u));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->payload($this->find($request, $id)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('staff.create');

        $request->merge(['phone' => User::normalizePhone($request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:phone', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'required_without:email', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'locale' => ['nullable', Rule::in(config('wifizone.locales'))],
        ]);
        $owner = $request->user();

        $agent = DB::transaction(function () use ($data, $owner) {
            // Verrou sur l'abonnement : deux créations simultanées ne dépassent pas la limite d'agents.
            Subscription::lockForUpdate()->first();
            $count = User::where('tenant_id', $owner->tenant_id)->where('role_id', $this->agentRoleId())->count();
            $this->entitlements->assertCanAdd('max_agents', $count);

            $agent = new User($data);
            $agent->tenant_id = $owner->tenant_id;
            $agent->role_id = $this->agentRoleId();
            $agent->save();

            return $agent;
        });

        $this->audit->log('agent.created', $owner, $agent, ['name' => $agent->name]);

        return ApiResponse::ok($this->payload($agent), status: 201);
    }

    /** Nom, langue, statut (ACTIVE/DISABLED) et mot de passe. Désactiver ou changer le mot de passe coupe toutes les sessions. */
    public function update(Request $request, string $id): JsonResponse
    {
        $agent = $this->find($request, $id);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'locale' => ['sometimes', Rule::in(config('wifizone.locales'))],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_DISABLED])],
            'password' => ['sometimes', 'string', 'min:8', 'max:128'],
        ]);

        if (($data['status'] ?? null) === User::STATUS_ACTIVE && $agent->status !== User::STATUS_ACTIVE) {
            $count = User::where('tenant_id', $agent->tenant_id)->where('role_id', $agent->role_id)->where('status', User::STATUS_ACTIVE)->count();
            if (! $this->entitlements->canAdd('max_agents', $count)) {
                throw ValidationException::withMessages(['status' => 'Limite d\'agents actifs atteinte pour votre formule.']);
            }
        }

        $agent->fill(array_diff_key($data, ['status' => 1]));
        if (isset($data['status'])) {
            $agent->status = $data['status'];
        }
        $agent->save();

        $revoke = (isset($data['status']) && $data['status'] === User::STATUS_DISABLED) || isset($data['password']);
        if ($revoke) {
            $agent->tokens()->delete();          // jetons d'accès Sanctum
            $this->tokens->revokeAllFor($agent); // jetons de rafraîchissement
        }

        $this->audit->log('agent.updated', $request->user(), $agent, [
            'fields' => array_keys(array_diff_key($data, ['password' => 1])), 'password_changed' => isset($data['password']),
        ]);

        return ApiResponse::ok($this->payload($agent));
    }

    private function find(Request $request, string $id): User
    {
        // Même réponse (404) pour un identifiant inexistant, d'un autre tenant ou qui n'est pas un agent.
        $target = $this->agents($request)->whereKey($id)->firstOrFail();
        Gate::authorize('staff.manage', $target);

        return $target;
    }

    private function agents(Request $request)
    {
        return User::query()->where('tenant_id', $request->user()->tenant_id)->where('role_id', $this->agentRoleId());
    }

    private function agentRoleId(): int
    {
        return (int) Role::where('code', Role::AGENT)->value('id');
    }

    /** @return array<string, mixed> */
    private function payload(User $u): array
    {
        return [
            'id' => $u->getKey(), 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'locale' => $u->locale, 'status' => $u->status, 'last_login_at' => $u->last_login_at?->toIso8601String(),
        ];
    }
}
