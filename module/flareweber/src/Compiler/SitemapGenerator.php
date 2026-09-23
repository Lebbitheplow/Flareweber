<?php

namespace FlareWeber\Compiler;

/**
 * sitemap.xml and robots.txt for the compiled site.
 */
class SitemapGenerator
{
    /**
     * @param array<int, string> $pagePaths canonical page paths
     */
    public function sitemap(array $pagePaths, UrlContext $ctx, string $lastmod): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($pagePaths as $path) {
            $path = PagePath::normalize($path);
            if ($path === '/thank-you' || preg_match('#/page/\d+$#', $path) === 1) {
                continue;
            }

            $loc = $ctx->absolute(PagePath::output($path));
            $xml .= "  <url><loc>" . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>"
                . "<lastmod>" . htmlspecialchars($lastmod, ENT_XML1, 'UTF-8') . "</lastmod></url>\n";
        }

        return $xml . "</urlset>\n";
    }

    public function robots(UrlContext $ctx): string
    {
        $txt = "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /thank-you/\n";

        if ($ctx->publicUrl !== null) {
            $txt .= 'Sitemap: ' . $ctx->publicUrl . "/sitemap.xml\n";
        }

        return $txt;
    }
}
