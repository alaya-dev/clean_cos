<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Actions\RecordAuditEventAction;
use App\Domain\Content\Models\StaticPage;
use App\Domain\Content\Services\HomepageCache;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\UpdateStaticPageRequest;
use App\Support\Content\RichTextSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StaticPageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => StaticPage::query()->orderBy('id')->get()]);
    }

    public function show(StaticPage $staticPage): JsonResponse
    {
        return response()->json(['data' => $staticPage]);
    }

    public function update(UpdateStaticPageRequest $request, StaticPage $staticPage, RichTextSanitizer $sanitizer, HomepageCache $cache, RecordAuditEventAction $audit): JsonResponse
    {
        $payload = $request->validated();
        if (isset($payload['content'])) {
            $payload['content'] = $sanitizer->sanitize($payload['content']);
        }
        $before = $staticPage->only(['title', 'slug', 'is_active', 'seo_title', 'seo_description']);
        DB::transaction(function () use ($staticPage, $payload): void {
            $oldSlug = $staticPage->slug;
            $staticPage->update($payload);
            if ($staticPage->slug !== $oldSlug) {
                DB::table('url_redirects')->updateOrInsert(['from_path' => '/pages/'.$oldSlug], ['to_path' => '/pages/'.$staticPage->slug, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $cache->forget();
        $audit->handle('content.static_page_updated', $staticPage, $request->user(), $before, $staticPage->only(array_keys($before)));

        return response()->json(['data' => $staticPage->fresh()]);
    }
}
