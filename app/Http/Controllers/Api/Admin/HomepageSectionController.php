<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Models\HomepageSection;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveHomepageSectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HomepageSectionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => HomepageSection::query()->with('products:id,public_id,name')->orderBy('sort_order')->get()]);
    }

    public function store(SaveHomepageSectionRequest $request, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $section = DB::transaction(function () use ($request): HomepageSection {
            $payload = $request->safe()->except('product_public_ids');
            $section = HomepageSection::query()->create($payload);
            $this->syncProducts($section, $request->validated('product_public_ids', []));

            return $section;
        });
        $cache->forget();
        $audit->handle('content.homepage_section_created', $section, $request->user(), after: $section->only(['type', 'title', 'is_active', 'sort_order']));

        return response()->json(['data' => $section->load('products')], 201);
    }

    public function update(SaveHomepageSectionRequest $request, HomepageSection $homepageSection, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $before = $homepageSection->only(['type', 'title', 'is_active', 'sort_order']);
        DB::transaction(function () use ($request, $homepageSection): void {
            $homepageSection->update($request->safe()->except('product_public_ids'));
            if ($request->has('product_public_ids')) {
                $this->syncProducts($homepageSection, $request->validated('product_public_ids', []));
            }
        });
        $cache->forget();
        $audit->handle('content.homepage_section_updated', $homepageSection, $request->user(), $before, $homepageSection->only(array_keys($before)));

        $homepageSection->refresh();

        return response()->json(['data' => $homepageSection->load('products')]);
    }

    public function reorder(Request $request, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $payload = $request->validate(['items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'between:0,1000']]);
        DB::transaction(function () use ($payload): void {
            foreach ($payload['items'] as $position) {
                HomepageSection::query()->where('public_id', $position['public_id'])->update(['sort_order' => $position['sort_order']]);
            }
        });
        $section = HomepageSection::query()->where('public_id', $payload['items'][0]['public_id'])->firstOrFail();
        $cache->forget();
        $audit->handle('content.homepage_sections_reordered', $section, $request->user(), after: ['count' => count($payload['items'])]);

        return response()->json(['data' => null]);
    }

    public function destroy(Request $request, HomepageSection $homepageSection, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        abort_unless($request->user()?->can('store.manage') === true, 403);

        DB::transaction(function () use ($request, $homepageSection, $audit): void {
            $audit->handle('content.homepage_section_deleted', $homepageSection, $request->user(), before: $homepageSection->only(['type', 'title']));
            $homepageSection->delete();
        });
        $cache->forget();

        return response()->json(['data' => null]);
    }

    /** @param array<int, string> $publicIds */
    private function syncProducts(HomepageSection $section, array $publicIds): void
    {
        $products = Product::query()->whereIn('public_id', $publicIds)->get(['id', 'public_id'])->keyBy('public_id');
        $positions = [];
        foreach ($publicIds as $sortOrder => $publicId) {
            $product = $products->get($publicId);
            if ($product instanceof Product) {
                $positions[$product->id] = ['sort_order' => $sortOrder];
            }
        }
        $section->products()->sync($positions);
    }
}
