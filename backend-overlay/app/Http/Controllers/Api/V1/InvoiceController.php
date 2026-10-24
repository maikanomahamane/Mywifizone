<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $page = Invoice::with('plan')->orderByDesc('created_at')->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::ok(
            collect($page->items())->map(fn (Invoice $i) => $i->toPayload())->all(),
            ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        );
    }

    // Résolution explicite par id, sous scope tenant : une facture d'un autre tenant donne 404.
    public function show(string $id): JsonResponse
    {
        return ApiResponse::ok(Invoice::with('plan')->findOrFail($id)->toPayload());
    }
}
