<?php

namespace FlareWeber\Http\Controllers\Admin;

use FlareWeber\Admin\ContentRepository;
use FlareWeber\Admin\SiteResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangesController extends ApiController
{
    /**
     * Content edited after the site's last publish (everything when the site
     * has never been published).
     */
    public function index(Request $request, ContentRepository $content): JsonResponse
    {
        $site = SiteResolver::fromRequest($request);
        if ($site === SiteResolver::NOT_FOUND) {
            return $this->notFound('site_not_found');
        }

        return response()->json($content->changesSince($site?->published_at));
    }
}
