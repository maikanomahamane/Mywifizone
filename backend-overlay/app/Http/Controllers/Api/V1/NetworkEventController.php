<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NetworkEvent;
use App\Support\ApiResponse;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Journal réseau d'un tenant (lecture seule). Protégé par la permission routers.view au niveau des routes. */
class NetworkEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'router_id' => ['nullable', 'uuid'],
            'severity' => ['nullable', Rule::in(['INFO', 'WARNING', 'ERROR'])],
        ]);

        $page = NetworkEvent::query()
            ->when($f['router_id'] ?? null, fn ($q, $v) => $q->where('router_id', $v))
            ->when($f['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(Rules::perPage($request));

        return ApiResponse::paginated($page, fn (NetworkEvent $e) => $e->toPayload());
    }
}
