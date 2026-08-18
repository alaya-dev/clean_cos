<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteProductImageFiles;
use App\Jobs\ProcessProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductImageController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=8000,max_height=8000'], 'alt_text' => ['nullable', 'string', 'max:255'], 'variant_public_id' => ['nullable', 'ulid'], 'is_primary' => ['nullable', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0']], ['image.max' => 'L’image ne doit pas dépasser 2 Mo.']);
        $uploaded = $request->file('image');
        $imageInfo = @getimagesize($uploaded->getRealPath());
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        abort_unless($imageInfo !== false && in_array($imageInfo['mime'], $allowedMimeTypes, true), 422, 'Fichier image invalide.');
        abort_if(($imageInfo[0] * $imageInfo[1]) > 20_000_000, 422, 'L’image est trop grande.');

        $variant = isset($data['variant_public_id']) ? $product->variants()->where('public_id', $data['variant_public_id'])->firstOrFail() : null;
        $staged = $request->file('image')->store('product-staging', 'local');
        $image = DB::transaction(function () use ($data, $product, $staged, $variant): ProductImage {
            if (($data['is_primary'] ?? false)) {
                $product->images()->update(['is_primary' => false]);
            }

            $image = $product->images()->create(['product_variant_id' => $variant?->id, 'original_path' => $staged, 'alt_text' => $data['alt_text'] ?? null, 'is_primary' => $data['is_primary'] ?? ! $product->images()->exists(), 'sort_order' => $data['sort_order'] ?? 0, 'processing_status' => 'pending']);
            ProcessProductImage::dispatch($image->id)->afterCommit();

            return $image;
        });

        return response()->json(['data' => ['public_id' => $image->public_id, 'processing_status' => $image->processing_status]], 201);
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        DB::transaction(function () use ($image, $product): void {
            $wasPrimary = $image->is_primary;
            $paths = [$image->path, $image->original_path, $image->renditions];
            $image->delete();
            if ($wasPrimary) {
                $product->images()->orderBy('sort_order')->first()?->update(['is_primary' => true]);
            }
            DeleteProductImageFiles::dispatch($paths[0], $paths[1], $paths[2])->afterCommit();
        });

        return response()->json(['data' => null]);
    }

    public function update(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:255'], 'variant_public_id' => ['nullable', 'ulid'], 'is_primary' => ['nullable', 'boolean'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
        if (array_key_exists('variant_public_id', $data)) {
            $data['product_variant_id'] = $data['variant_public_id'] ? $product->variants()->where('public_id', $data['variant_public_id'])->firstOrFail()->id : null;
        }
        unset($data['variant_public_id']);
        if (($data['is_primary'] ?? false) === true) {
            $product->images()->whereKeyNot($image->id)->update(['is_primary' => false]);
        }
        $image->update($data);

        return response()->json(['data' => $image->fresh()]);
    }

    public function reorder(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:150'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'min:0']]);
        $items = $data['items'];
        abort_unless(is_array($items), 422, 'Les images sont invalides.');
        $publicIds = [];
        foreach ($items as $item) {
            abort_unless(is_array($item) && is_string($item['public_id'] ?? null) && is_int($item['sort_order'] ?? null), 422, 'Une image est invalide.');
            $publicIds[] = $item['public_id'];
        }
        abort_unless($product->images()->whereIn('public_id', $publicIds)->count() === count($publicIds), 422, 'Une image est invalide.');
        DB::transaction(function () use ($items, $product): void {
            foreach ($items as $item) {
                $product->images()->where('public_id', $item['public_id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['data' => null]);
    }
}
