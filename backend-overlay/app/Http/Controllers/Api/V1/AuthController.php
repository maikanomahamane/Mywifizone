<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use App\Services\TokenService;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $context,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $request->merge(['phone' => User::normalizePhone($request->input('phone'))]);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+?\d{8,15}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'locale' => ['nullable', Rule::in(config('wifizone.locales'))],
            'device_name' => ['nullable', 'string', 'max:100'],
            'plan_code' => ['nullable', 'string', Rule::exists('subscription_plans', 'code')->where('is_active', true)],
            'promo_code' => ['nullable', 'string', 'max:40'], // extension d'essai
        ]);

        $owner = Role::where('code', Role::OWNER)->firstOrFail();
        $plan = SubscriptionPlan::where('code', $data['plan_code'] ?? config('wifizone.default_plan'))->where('is_active', true)->first()
            ?? throw new ApiException('plan_unavailable', 'Aucune formule disponible pour le moment.', 503);
        $promotion = ! empty($data['promo_code'])
            ? $this->subscriptions->findPromotion($data['promo_code'], $plan->code, true)
            : null;

        [$tenant, $user] = DB::transaction(function () use ($data, $owner, $plan, $promotion) {
            $tenant = Tenant::create([
                'name' => $data['business_name'],
                'locale' => $data['locale'] ?? 'fr',
            ]);

            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'fr',
            ]);
            // Attribués explicitement, hors mass-assignment
            $user->tenant_id = $tenant->getKey();
            $user->role_id = $owner->getKey();
            $user->save();

            // Essai gratuit : l'abonnement est la source unique de vérité (statut TRIAL + date de fin).
            $this->context->run($tenant->getKey(), fn () => $this->subscriptions->startTrial($tenant, $plan, $promotion));

            return [$tenant, $user];
        });

        $this->audit->log('auth.register', $user, $tenant, tenantId: $tenant->getKey());

        return ApiResponse::ok(
            ['user' => $this->userPayload($user), 'tokens' => $this->tokens->issue($user, $data['device_name'] ?? null, $request->ip())],
            status: 201,
        );
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $identifier = trim($data['identifier']);
        $user = User::query()
            ->where(function ($q) use ($identifier) {
                $q->where('email', mb_strtolower($identifier));
                if ($phone = User::normalizePhone($identifier)) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->first();

        // Vérification faite même si l'utilisateur n'existe pas (temps comparable, pas d'énumération de comptes).
        // Le hash factice est généré UNE fois par processus, avec le même coût que les vrais hashes.
        static $dummyHash = null;
        $dummyHash ??= Hash::make(Str::random(32));
        $valid = Hash::check($data['password'], $user?->password ?? $dummyHash);

        if (! $user || ! $valid) {
            $this->audit->log('auth.login_failed', null, null, ['identifier' => mb_substr($identifier, 0, 3).'***']);

            return ApiResponse::error('invalid_credentials', 'Identifiants invalides.', 401);
        }

        if (! $user->isActive() || ($user->tenant && $user->tenant->isSuspended())) {
            $this->audit->log('auth.login_blocked', $user);

            return ApiResponse::error($user->isActive() ? 'tenant_suspended' : 'account_disabled', 'Accès refusé.', 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user);

        return ApiResponse::ok([
            'user' => $this->userPayload($user),
            'tokens' => $this->tokens->issue($user, $data['device_name'] ?? null, $request->ip()),
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate(['refresh_token' => ['required', 'string', 'max:200']]);

        return DB::transaction(function () use ($data, $request) {
            $presented = $this->tokens->find($data['refresh_token']);
            $current = $presented ? RefreshToken::whereKey($presented->getKey())->lockForUpdate()->first() : null;

            if (! $current) {
                return ApiResponse::error('invalid_refresh_token', 'Jeton invalide.', 401);
            }

            $user = User::find($current->user_id);

            if ($current->isRevoked()) {
                // Jeton déjà CONSOMMÉ par une rotation (replaced_by renseigné) : rejeu => vol probable,
                // on coupe toutes les sessions. Un jeton simplement révoqué par logout est refusé sans plus.
                if ($current->replaced_by !== null && $user) {
                    $this->tokens->revokeAllFor($user);
                    $this->audit->log('auth.refresh_reuse_detected', $user);
                }

                return ApiResponse::error('invalid_refresh_token', 'Jeton invalide.', 401);
            }

            if ($current->isExpired() || ! $user || ! $user->isActive() || ($user->tenant && $user->tenant->isSuspended())) {
                return ApiResponse::error('invalid_refresh_token', 'Jeton invalide.', 401);
            }

            $new = $this->tokens->issue($user, $current->device_name, $request->ip());
            $newId = explode('|', $new['refresh_token'], 2)[0];

            $current->update(['revoked_at' => now(), 'replaced_by' => $newId]);
            if ($current->access_token_id) {
                PersonalAccessToken::whereKey($current->access_token_id)->delete();
            }

            $this->audit->log('auth.refresh', $user);

            return ApiResponse::ok(['tokens' => $new]);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            RefreshToken::where('access_token_id', $token->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $token->delete();
        }

        $this->audit->log('auth.logout', $user);

        return ApiResponse::ok(null);
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::ok(['user' => $this->userPayload($request->user())]);
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        $user->loadMissing('role', 'tenant');

        // Hors requête tenant-scopée (login, register) : lecture explicite par tenant_id.
        $subscription = $user->tenant_id
            ? Subscription::withoutGlobalScopes()->with('plan')->where('tenant_id', $user->tenant_id)->first()
            : null;

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'role' => $user->role->code,
            'permissions' => $user->permissionCodes(),
            'tenant' => $user->tenant ? [
                'id' => $user->tenant->getKey(),
                'name' => $user->tenant->name,
                'status' => $user->tenant->status,
                'currency' => $user->tenant->currency,
            ] : null,
            'subscription' => $subscription?->toPayload(),
        ];
    }
}
