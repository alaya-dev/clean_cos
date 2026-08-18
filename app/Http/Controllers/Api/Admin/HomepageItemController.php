<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\BrandContent;
use App\Domain\Content\Models\EditorialSection;
use App\Domain\Content\Models\ReassuranceItem;
use App\Domain\Content\Models\SocialGalleryItem;
use App\Domain\Content\Models\VisualCategoryTile;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveHomepageItemRequest;
use App\Support\Content\RichTextSanitizer;
use App\Support\Media\SecureImageProcessor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class HomepageItemController extends Controller
{
    public function index(string $contentType): JsonResponse
    {
        $query = $this->modelClass($contentType)::query();
        if ($contentType === 'visual-tiles') {
            $query->with('category:id,public_id,name');
        }
        if ($contentType === 'editorial') {
            $query->with('products:id,public_id,name');
        }
        if (in_array($contentType, ['visual-tiles', 'reassurance', 'social'], true)) {
            $query->orderBy('sort_order');
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(SaveHomepageItemRequest $request, string $contentType, SecureImageProcessor $images, RichTextSanitizer $sanitizer, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $this->enforceSingleton($contentType);
        $this->authorizeReassuranceActivation($contentType, $request->boolean('is_active'));
        $payload = $this->payload($request, $contentType, $images, $sanitizer);
        $modelClass = $this->modelClass($contentType);
        try {
            $model = DB::transaction(function () use ($modelClass, $payload, $request): Model {
                $model = $modelClass::query()->create($payload);
                $this->syncEditorialProducts($model, $request->validated('product_public_ids', []));

                return $model;
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($this->imagePaths($payload));
            throw $exception;
        }
        $cache->forget();
        $audit->handle('content.'.$contentType.'_created', $model, $request->user());

        return response()->json(['data' => $model->fresh()], 201);
    }

    public function update(SaveHomepageItemRequest $request, string $contentType, string $contentItem, SecureImageProcessor $images, RichTextSanitizer $sanitizer, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $model = $this->find($contentType, $contentItem);
        $this->authorizeReassuranceActivation($contentType, $request->boolean('is_active') && ! (bool) $model->getAttribute('is_active'));
        $payload = $this->payload($request, $contentType, $images, $sanitizer);
        $newPaths = $this->imagePaths($payload);
        $previousPaths = $this->replacedImagePaths($request, $model);
        try {
            DB::transaction(function () use ($model, $payload, $request): void {
                $model->update($payload);
                if ($request->has('product_public_ids')) {
                    $this->syncEditorialProducts($model, $request->validated('product_public_ids', []));
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPaths);
            throw $exception;
        }
        Storage::disk('public')->delete($previousPaths);
        $cache->forget();
        $audit->handle('content.'.$contentType.'_updated', $model, $request->user());

        return response()->json(['data' => $model->fresh()]);
    }

    public function reorder(Request $request, string $contentType, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        if (! in_array($contentType, ['visual-tiles', 'reassurance', 'social'], true)) {
            abort(404);
        }
        $payload = $request->validate(['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'between:0,1000']]);
        $modelClass = $this->modelClass($contentType);
        DB::transaction(function () use ($payload, $modelClass): void {
            foreach ($payload['items'] as $position) {
                $modelClass::query()->where('public_id', $position['public_id'])->update(['sort_order' => $position['sort_order']]);
            }
        });
        $cache->forget();
        $model = $this->find($contentType, $payload['items'][0]['public_id']);
        $audit->handle('content.'.$contentType.'_reordered', $model, $request->user(), after: ['count' => count($payload['items'])]);

        return response()->json(['data' => null]);
    }

    public function destroy(Request $request, string $contentType, string $contentItem, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $model = $this->find($contentType, $contentItem);
        $imagePaths = $this->imagePaths($model->getAttributes());
        $audit->handle('content.'.$contentType.'_deleted', $model, $request->user());
        $model->delete();
        Storage::disk('public')->delete($imagePaths);
        $cache->forget();

        return response()->json(['data' => null]);
    }

    /** @return class-string<Model> */
    private function modelClass(string $contentType): string
    {
        return match ($contentType) {
            'visual-tiles' => VisualCategoryTile::class, 'reassurance' => ReassuranceItem::class,
            'social' => SocialGalleryItem::class, 'editorial' => EditorialSection::class, 'brand' => BrandContent::class,
            default => throw ValidationException::withMessages(['content_type' => 'Cette section n’est pas autorisée.']),
        };
    }

    private function find(string $contentType, string $identifier): Model
    {
        $modelClass = $this->modelClass($contentType);
        $column = in_array($contentType, ['editorial', 'brand'], true) ? 'id' : 'public_id';

        return $modelClass::query()->where($column, $identifier)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(SaveHomepageItemRequest $request, string $contentType, SecureImageProcessor $images, RichTextSanitizer $sanitizer): array
    {
        $payload = $request->safe()->except(['image', 'desktop_image', 'mobile_image', 'category_public_id', 'product_public_ids']);
        if ($request->has('category_public_id')) {
            $payload['category_id'] = Category::query()->where('public_id', $request->validated('category_public_id'))->value('id');
        }
        $storedPaths = [];
        try {
            foreach (['image' => 'image_path', 'desktop_image' => 'desktop_image_path', 'mobile_image' => 'mobile_image_path'] as $input => $column) {
                if ($request->hasFile($input)) {
                    $payload[$column] = $storedPaths[] = $images->store($request->file($input), 'public', 'homepage')['path'];
                }
            }
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($storedPaths);
            throw $exception;
        }
        if ($contentType === 'brand' && isset($payload['content'])) {
            $payload['content'] = $sanitizer->sanitize($payload['content']);
        }

        return $payload;
    }

    /** @param array<string, mixed> $attributes
     * @return array<int, string>
     */
    private function imagePaths(array $attributes): array
    {
        return array_values(array_filter([
            $attributes['image_path'] ?? null,
            $attributes['desktop_image_path'] ?? null,
            $attributes['mobile_image_path'] ?? null,
        ], 'is_string'));
    }

    /** @return array<int, string> */
    private function replacedImagePaths(SaveHomepageItemRequest $request, Model $model): array
    {
        $paths = [];
        foreach (['image' => 'image_path', 'desktop_image' => 'desktop_image_path', 'mobile_image' => 'mobile_image_path'] as $input => $column) {
            $path = $model->getAttribute($column);
            if ($request->hasFile($input) && is_string($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @param array<int, string> $productIds */
    private function syncEditorialProducts(Model $model, array $productIds): void
    {
        if (! $model instanceof EditorialSection) {
            return;
        }
        $products = Product::query()->whereIn('public_id', $productIds)->get(['id', 'public_id'])->keyBy('public_id');
        $sync = [];
        foreach ($productIds as $position => $publicId) {
            $product = $products->get($publicId);
            if ($product instanceof Product) {
                $sync[$product->id] = ['sort_order' => $position];
            }
        }
        $model->products()->sync($sync);
    }

    private function enforceSingleton(string $contentType): void
    {
        if (in_array($contentType, ['editorial', 'brand'], true) && $this->modelClass($contentType)::query()->exists()) {
            throw ValidationException::withMessages(['section' => 'Cette section existe déjà. Modifiez-la.']);
        }
    }

    private function authorizeReassuranceActivation(string $contentType, bool $activating): void
    {
        if ($contentType === 'reassurance' && $activating && ReassuranceItem::query()->where('is_active', true)->count() >= (int) config('store.reassurance_limit')) {
            throw ValidationException::withMessages(['is_active' => 'Quatre éléments de réassurance actifs sont autorisés.']);
        }
    }
}
