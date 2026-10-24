<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Portal\ExternalUrl;
use App\Http\Controllers\Controller;
use App\Models\ExternalLink;
use App\Services\AuditLogger;
use App\Support\ApiException;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Services externes (ex. Starlink) : de simples liens vers le portail OFFICIEL, ouverts dans le navigateur.
 * On ne demande ni ne stocke jamais d'identifiant ou de mot de passe ; aucune API Starlink n'est appelée ni inventée.
 */
class ExternalLinkController extends Controller
{
    private const MAX_LINKS = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok(ExternalLink::orderBy('name')->get()->map->toPayload()->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        if (ExternalLink::count() >= self::MAX_LINKS) {
            throw new ApiException('plan_limit_reached', 'Nombre maximal de liens atteint.', 422);
        }
        $link = ExternalLink::create($data);
        $this->audit->log('external_link.created', null, $link, ['kind' => $link->kind]);

        return ApiResponse::ok($link->toPayload(), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $link = ExternalLink::findOrFail($id);
        $link->fill($this->validated($request, false))->save();
        $this->audit->log('external_link.updated', null, $link, ['kind' => $link->kind]);

        return ApiResponse::ok($link->toPayload());
    }

    public function destroy(string $id): JsonResponse
    {
        $link = ExternalLink::findOrFail($id);
        $link->delete();
        $this->audit->log('external_link.deleted', null, $link, ['kind' => $link->kind]);

        return ApiResponse::ok(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'kind' => ['sometimes', Rule::in(['STARLINK', 'OTHER'])],
            'name' => [$req, 'string', 'min:1', 'max:80'],
            'url' => [$req, 'string', 'max:300', function (string $a, mixed $v, \Closure $fail) {
                if (! ExternalUrl::isValid($v)) {
                    $fail('Adresse https valide requise (sans identifiants intégrés).');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
