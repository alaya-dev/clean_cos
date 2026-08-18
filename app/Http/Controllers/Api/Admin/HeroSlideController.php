<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Content\Models\HeroSlide;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\SaveHeroSlideRequest;
use App\Support\Media\SecureImageProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class HeroSlideController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => HeroSlide::query()->orderBy('sort_order')->get()]);
    }

    public function store(SaveHeroSlideRequest $request, SecureImageProcessor $images, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $this->enforceActiveLimit((bool) $request->boolean('is_active'));
        $payload = $request->safe()->except(['desktop_image', 'mobile_image']);
        $newPaths = [];
        try {
            $payload['desktop_image_path'] = $newPaths[] = $images->store($request->file('desktop_image'), 'public', 'heroes')['path'];
            if ($request->hasFile('mobile_image')) {
                $payload['mobile_image_path'] = $newPaths[] = $images->store($request->file('mobile_image'), 'public', 'heroes')['path'];
            }
            $slide = HeroSlide::query()->create($payload);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPaths);
            throw $exception;
        }
        $cache->forget();
        $audit->handle('content.hero_created', $slide, $request->user(), after: $slide->only(['admin_label', 'is_active', 'sort_order']));

        return response()->json(['data' => $slide], 201);
    }

    public function update(SaveHeroSlideRequest $request, HeroSlide $heroSlide, SecureImageProcessor $images, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $activating = $request->boolean('is_active') && ! $heroSlide->is_active;
        $this->enforceActiveLimit($activating);
        $payload = $request->safe()->except(['desktop_image', 'mobile_image']);
        $newPaths = [];
        $previousPaths = [];
        try {
            foreach (['desktop_image' => 'desktop_image_path', 'mobile_image' => 'mobile_image_path'] as $input => $column) {
                if ($request->hasFile($input)) {
                    if ($heroSlide->{$column}) {
                        $previousPaths[] = $heroSlide->{$column};
                    }
                    $payload[$column] = $newPaths[] = $images->store($request->file($input), 'public', 'heroes')['path'];
                }
            }
            $before = $heroSlide->only(['admin_label', 'is_active', 'sort_order']);
            $heroSlide->update($payload);
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newPaths);
            throw $exception;
        }
        Storage::disk('public')->delete($previousPaths);
        $cache->forget();
        $audit->handle('content.hero_updated', $heroSlide, $request->user(), $before, $heroSlide->only(array_keys($before)));

        return response()->json(['data' => $heroSlide->fresh()]);
    }

    public function reorder(Request $request, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $payload = $request->validate(['items' => ['required', 'array', 'min:1', 'max:10'], 'items.*.public_id' => ['required', 'ulid', 'distinct'], 'items.*.sort_order' => ['required', 'integer', 'between:0,1000']]);
        DB::transaction(function () use ($payload): void {
            foreach ($payload['items'] as $position) {
                HeroSlide::query()->where('public_id', $position['public_id'])->update(['sort_order' => $position['sort_order']]);
            }
        });
        $cache->forget();
        $slide = HeroSlide::query()->where('public_id', $payload['items'][0]['public_id'])->firstOrFail();
        $audit->handle('content.heroes_reordered', $slide, $request->user(), after: ['count' => count($payload['items'])]);

        return response()->json(['data' => null]);
    }

    public function destroy(Request $request, HeroSlide $heroSlide, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        Storage::disk('public')->delete(array_filter([$heroSlide->desktop_image_path, $heroSlide->mobile_image_path]));
        $audit->handle('content.hero_deleted', $heroSlide, $request->user());
        $heroSlide->delete();
        $cache->forget();

        return response()->json(['data' => null]);
    }

    private function enforceActiveLimit(bool $activating): void
    {
        if ($activating && HeroSlide::query()->where('is_active', true)->count() >= (int) config('store.hero_active_limit')) {
            throw ValidationException::withMessages(['is_active' => 'Le nombre maximal de diapositives actives est atteint.']);
        }
    }
}
