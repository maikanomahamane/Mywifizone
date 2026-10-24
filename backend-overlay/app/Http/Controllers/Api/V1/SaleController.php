<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\HotspotProfile;
use App\Models\Router;
use App\Models\Sale;
use App\Models\Voucher;
use App\Services\SaleService;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Sale::class);

        $f = $request->validate([
            'status' => ['nullable', Rule::in([Sale::COMPLETED, Sale::VOIDED])],
            'agent_id' => ['nullable', 'uuid'],
            'site_id' => ['nullable', 'uuid'],
            'profile_id' => ['nullable', 'uuid'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::ALL)],
            'currency' => ['nullable', 'alpha:ascii', 'size:3'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $user = $request->user();

        $page = Sale::query()
            // Un agent ne liste que ses ventes, quoi qu'il demande.
            ->when(! $user->hasPermission('sales.view_all'), fn ($q) => $q->where('agent_id', $user->getKey()))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['agent_id'] ?? null, fn ($q, $v) => $q->where('agent_id', $v))
            ->when($f['site_id'] ?? null, fn ($q, $v) => $q->where('site_id', $v))
            ->when($f['profile_id'] ?? null, fn ($q, $v) => $q->where('profile_id', $v))
            ->when($f['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($f['currency'] ?? null, fn ($q, $v) => $q->where('currency', strtoupper($v)))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('sold_on', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('sold_on', '<=', $v))
            ->orderByDesc('sold_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (Sale $s) => $s->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);
        Gate::authorize('view', $sale);

        return ApiResponse::ok($sale->toPayload());
    }

    /**
     * Vend un voucher en stock : soit un voucher précis, soit « n'importe lequel » d'un profil sur un routeur.
     * `client_ref` rend l'appel rejouable sans doublon (réseau mobile instable) : le rejeu renvoie la vente d'origine (200).
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Sale::class);

        $data = $request->validate([
            'voucher_id' => ['nullable', 'uuid', Rules::ownedBy(Voucher::class)],
            'router_id' => ['required_without:voucher_id', 'nullable', 'uuid', Rules::ownedBy(Router::class)],
            'profile_id' => ['required_without:voucher_id', 'nullable', 'uuid', Rules::ownedBy(HotspotProfile::class)],
            'payment_method' => ['required', Rule::in(PaymentMethod::ALL)],
            'provider_transaction_id' => ['nullable', 'string', 'max:100'],
            'client_ref' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        [$sale, $created] = $this->sales->create($request->user(), $data);
        $sale->loadMissing('voucher');

        // Un rejeu ne ré-expose pas le code à qui n'a pas la vente d'origine : seul le vendeur d'origine ou un propriétaire le revoit.
        $mayShowCode = $created || $sale->agent_id === $request->user()->getKey() || $request->user()->hasPermission('sales.view_all');

        return ApiResponse::ok($sale->toPayload($mayShowCode), status: $created ? 201 : 200);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $sale = Sale::findOrFail($id);
        Gate::authorize('void', $sale);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'revoke_voucher' => ['nullable', 'boolean'],
        ]);

        [$sale] = $this->sales->void($sale, $request->user(), $data['reason'], $request->boolean('revoke_voucher', true));

        return ApiResponse::ok($sale->toPayload());
    }
}
