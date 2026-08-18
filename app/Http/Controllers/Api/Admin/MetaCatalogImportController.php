<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\MetaTracking\Services\MetaCatalogImportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaCatalogImportController extends Controller
{
    public function dryRun(Request $request, MetaCatalogImportService $imports): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('catalog.manage'), 403);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']]);

        return ApiResponse::success($imports->dryRun($data['file']));
    }

    public function commit(Request $request, MetaCatalogImportService $imports, RecordAuditEventAction $audit): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('catalog.manage'), 403);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*.meta_catalog_id' => ['nullable', 'string', 'max:120'],
            'rows.*.name' => ['nullable', 'string', 'max:200'],
            'rows.*.price_millimes' => ['nullable', 'integer', 'min:0'],
            'rows.*.description' => ['nullable', 'string', 'max:10000'],
            'rows.*.category' => ['nullable', 'string', 'max:190'],
            'rows.*.category_slug' => ['nullable', 'string', 'max:190'],
            'rows.*.provided_fields' => ['nullable', 'array'],
            'rows.*.provided_fields.*' => ['in:meta_catalog_id,name,price,description,category'],
            'rows.*.operation' => ['required', 'in:update,create,skipped,conflict'],
            'rows.*.product_public_id' => ['nullable', 'ulid'],
        ]);
        $result = $imports->commit($data['rows']);
        $audit->handle('meta.catalogue_import_committed', $request->user(), $request->user(), after: ['updated' => $result['updated'], 'created' => $result['created'], 'skipped' => $result['skipped']]);

        return ApiResponse::success(['result' => $result]);
    }
}
