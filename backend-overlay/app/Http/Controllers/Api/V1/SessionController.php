<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Network\CommandType;
use App\Http\Controllers\Controller;
use App\Models\HotspotSession;
use App\Models\Router;
use App\Services\ConnectorManager;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SessionController extends Controller
{
    public function __construct(private readonly ConnectorManager $connectors) {}

    /** Sessions actives par défaut ; `active=0` pour l'historique récent. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', HotspotSession::class);

        $f = $request->validate(['router_id' => ['nullable', 'uuid'], 'active' => ['nullable', 'boolean'], 'username' => ['nullable', 'string', 'max:12']]);
        $active = ! array_key_exists('active', $f) || $request->boolean('active');

        $page = HotspotSession::query()
            ->when($active, fn ($q) => $q->whereNull('ended_at'), fn ($q) => $q->whereNotNull('ended_at'))
            ->when($f['router_id'] ?? null, fn ($q, $v) => $q->where('router_id', $v))
            ->when($f['username'] ?? null, fn ($q, $v) => $q->where('username', strtoupper($v)))
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (HotspotSession $s) => $s->toPayload());
    }

    /** Demande la déconnexion d'un client. Exécutée par l'agent au prochain cycle : 202. */
    public function disconnect(Request $request, string $id): JsonResponse
    {
        $session = HotspotSession::findOrFail($id);
        Gate::authorize('disconnect', $session);

        $router = Router::findOrFail($session->router_id);
        $command = $this->connectors->for($router)->send($router, CommandType::DISCONNECT_USER, [
            'session_id' => $session->remote_id,
            'username' => $session->username,
        ], $request->user()?->getKey());

        return ApiResponse::ok($command->toPayload(), status: 202);
    }
}
