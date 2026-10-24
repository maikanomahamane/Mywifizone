<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Observability\PrometheusText;
use App\Http\Controllers\Controller;
use App\Services\Observability\MetricsCollector;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sondes d'exploitation. `ready` est publique et ne révèle rien (ni version, ni détail) ; `metrics` exige un jeton d'exploitation
 * et n'expose que des agrégats sans identifiant de tenant, d'utilisateur ni de code.
 */
class OpsController extends Controller
{
    public function ready(): JsonResponse
    {
        $checks = ['database' => $this->probe(fn () => DB::select('select 1')), 'cache' => $this->probe(function () {
            $key = 'health:'.Str::random(8);
            Cache::put($key, '1', 10);

            return Cache::pull($key) === '1';
        })];
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'success' => $ok,
            'data' => ['status' => $ok ? 'ready' : 'degraded', 'checks' => array_map(fn ($c) => $c ? 'ok' : 'fail', $checks)],
            'meta' => (object) [],
            'errors' => [],
        ], $ok ? 200 : 503);
    }

    public function metrics(Request $request, MetricsCollector $collector): Response
    {
        $expected = (string) config('monitoring.metrics_token');
        if ($expected === '') {
            throw new ApiException('not_found', 'Ressource introuvable.', 404); // désactivé : l'endpoint n'existe pas
        }
        if (! hash_equals($expected, (string) $request->bearerToken())) {
            throw new ApiException('unauthenticated', 'Authentification requise.', 401);
        }

        return response(PrometheusText::render($collector->prometheus()), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private function probe(callable $fn): bool
    {
        try {
            return $fn() !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
