<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        return ApiResponse::ok(self::payload($tenant));
    }

    /** @return array<string, mixed> */
    public static function payload(\App\Models\Tenant $tenant): array
    {
        return [
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'country' => $tenant->country,
            'locale' => $tenant->locale,
            'currency' => $tenant->currency,
            'timezone' => $tenant->timezone ?? 'Africa/Niamey',
            'status' => $tenant->status,
        ];
    }
}
