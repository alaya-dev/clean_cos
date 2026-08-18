<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UploadCategoryImageRequest;
use App\Support\Media\SecureImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CategoryImageController extends Controller
{
    public function store(UploadCategoryImageRequest $request, Category $category, SecureImageProcessor $images, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $processed = $images->store($request->file('image'), 'public', 'categories');
        $previousPath = $category->image_path;
        try {
            $category->update(['image_path' => $processed['path'], 'image_processing_status' => 'ready', 'image_width' => $processed['width'], 'image_height' => $processed['height']]);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($processed['path']);
            throw $exception;
        }
        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
        $cache->forget();
        $audit->handle('catalog.category_image_updated', $category, $request->user());

        $category->refresh();

        return response()->json(['data' => $category->toArray()]);
    }

    public function destroy(Request $request, Category $category, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $previousPath = $category->image_path;
        $category->update(['image_path' => null, 'image_processing_status' => null, 'image_width' => null, 'image_height' => null]);
        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
        $cache->forget();
        $audit->handle('catalog.category_image_deleted', $category, $request->user());

        $category->refresh();

        return response()->json(['data' => $category->toArray()]);
    }
}
