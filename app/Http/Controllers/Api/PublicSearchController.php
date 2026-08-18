<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Services\CatalogSearchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSearchController extends Controller
{
    public function __invoke(Request $request, CatalogSearchService $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'between:1,10']]);

        return response()->json(['data' => $search->suggestions($data['q'], $data['limit'] ?? 8), 'meta' => ['request_id' => $request->attributes->get('request_id')]]);
    }
}
