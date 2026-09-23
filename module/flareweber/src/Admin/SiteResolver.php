<?php

namespace FlareWeber\Admin;

use FlareWeber\Models\Site;
use Illuminate\Http\Request;

/**
 * Picks the site an admin API call refers to: the `site` query parameter
 * when given, otherwise the most recently created site (or null when none
 * exists yet).
 */
class SiteResolver
{
    public const NOT_FOUND = 'not_found';

    /**
     * @return Site|null|string Site, null when no site exists, or NOT_FOUND
     *                          when an explicit id does not match.
     */
    public static function fromRequest(Request $request): Site|string|null
    {
        $raw = $request->query('site');

        if ($raw === null || $raw === '') {
            return Site::query()->latest('id')->first();
        }

        if (!ctype_digit((string) $raw)) {
            return self::NOT_FOUND;
        }

        return Site::find((int) $raw) ?? self::NOT_FOUND;
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'ecommerce' => $site->requiresEcommerce(),
            'published_at' => $site->published_at?->toIso8601String(),
        ];
    }
}
