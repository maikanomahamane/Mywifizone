<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Enveloppe standard : { success, data, meta, errors }.
 * `errors[].code` est une clé stable que l'UI traduit (fr/en/ar/ha) ; `message` est technique.
 */
final class ApiResponse
{
    /** @param array<string, mixed> $meta */
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
            'errors' => [],
        ], $status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $code, string $message, int $status, array $errors = [], array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'meta' => (object) $meta,
            'errors' => $errors ?: [['code' => $code, 'message' => $message]],
        ], $status);
    }

    /** Liste paginée : `data` = éléments transformés par $map, `meta` = page, per_page, total. */
    public static function paginated(LengthAwarePaginator $page, callable $map): JsonResponse
    {
        return self::ok(
            collect($page->items())->map($map)->values()->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }
}
