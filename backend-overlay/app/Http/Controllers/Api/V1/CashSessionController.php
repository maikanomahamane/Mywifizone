<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\CashType;
use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\CashTransaction;
use App\Services\CashService;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CashSessionController extends Controller
{
    public function __construct(private readonly CashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CashSession::class);

        $f = $request->validate([
            'status' => ['nullable', Rule::in([CashSession::OPEN, CashSession::CLOSED])],
            'cash_register_id' => ['nullable', 'uuid'],
        ]);
        $user = $request->user();

        $page = CashSession::query()
            ->when(! $user->hasPermission('cash.manage'), fn ($q) => $q->where('opened_by', $user->getKey()))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['cash_register_id'] ?? null, fn ($q, $v) => $q->where('cash_register_id', $v))
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (CashSession $s) => $s->toPayload());
    }

    /** Ma session ouverte (ou null). */
    public function current(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CashSession::class);

        return ApiResponse::ok($this->cash->openSessionOf($request->user())?->toPayload());
    }

    public function show(string $id): JsonResponse
    {
        $session = CashSession::findOrFail($id);
        Gate::authorize('view', $session);

        return ApiResponse::ok($session->toPayload());
    }

    public function open(Request $request): JsonResponse
    {
        Gate::authorize('open', CashSession::class);

        $data = $request->validate([
            'cash_register_id' => ['required', 'uuid', Rules::ownedBy(CashRegister::class)],
            'opening_balance' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $session = $this->cash->open($request->user(), CashRegister::findOrFail($data['cash_register_id']), $data['opening_balance'], $data['note'] ?? null);

        return ApiResponse::ok($session->toPayload(), status: 201);
    }

    public function transactions(Request $request, string $id): JsonResponse
    {
        $session = CashSession::findOrFail($id);
        Gate::authorize('view', $session);

        $page = CashTransaction::where('cash_session_id', $session->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request, 50));

        return ApiResponse::paginated($page, fn (CashTransaction $t) => $t->toPayload());
    }

    public function record(Request $request, string $id): JsonResponse
    {
        $session = CashSession::findOrFail($id);
        Gate::authorize('operate', $session);

        $data = $request->validate([
            'type' => ['required', Rule::in([CashType::CASH_IN, CashType::EXPENSE, CashType::WITHDRAWAL, CashType::ADJUSTMENT])],
            'amount' => ['required', 'integer', 'between:-1000000000000,1000000000000', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        // Une dépense/sortie/entrée se saisit en positif : seul AJUSTEMENT est signé.
        if ($data['type'] !== CashType::ADJUSTMENT && $data['amount'] < 0) {
            throw ValidationException::withMessages(['amount' => 'Le montant doit être positif.']);
        }

        $tx = $this->cash->record($session, $request->user(), $data['type'], $data['amount'], $data['note'] ?? null);

        return ApiResponse::ok($tx->toPayload() + ['current_balance' => $session->refresh()->currentBalance()], status: 201);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $session = CashSession::findOrFail($id);
        Gate::authorize('operate', $session);

        $data = $request->validate([
            'counted_balance' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::ok($this->cash->close($session, $request->user(), $data['counted_balance'], $data['note'] ?? null)->toPayload());
    }
}
