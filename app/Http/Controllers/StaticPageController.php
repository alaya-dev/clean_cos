<?php

namespace App\Http\Controllers;

use App\Domain\Content\Models\StaticPage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class StaticPageController extends Controller
{
    public function show(string $slug): View|RedirectResponse
    {
        $page = StaticPage::query()->where('slug', $slug)->where('is_active', true)->first();
        if (! $page) {
            $destination = DB::table('url_redirects')->where('from_path', '/pages/'.$slug)->value('to_path');
            if (is_string($destination)) {
                return redirect($destination, 301);
            }
            abort(404);
        }

        return view('storefront.static-page', compact('page'));
    }
}
