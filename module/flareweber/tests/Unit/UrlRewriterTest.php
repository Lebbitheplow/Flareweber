<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Compiler\HtmlDocument;
use FlareWeber\Compiler\UrlContext;
use FlareWeber\Compiler\UrlRewriter;
use PHPUnit\Framework\TestCase;

class UrlRewriterTest extends TestCase
{
    private function rewriter(bool $mediaViaR2 = false, ?string $publicUrl = null): UrlRewriter
    {
        return new UrlRewriter(UrlContext::fromBaseUrl('http://127.0.0.1:8471', $publicUrl, $mediaViaR2));
    }

    public function testLocalOriginRequiresExactSchemeHostAndPort(): void
    {
        $ctx = UrlContext::fromBaseUrl('http://127.0.0.1:8471');

        $this->assertTrue($ctx->isLocal('http://127.0.0.1:8471/about'));
        $this->assertFalse($ctx->isLocal('http://127.0.0.1/about'), 'different port');
        $this->assertFalse($ctx->isLocal('https://127.0.0.1:8471/about'), 'different scheme');
        $this->assertFalse($ctx->isLocal('http://127.0.0.1.evil.com:8471/about'), 'substring host');
        $this->assertFalse($ctx->isLocal('//127.0.0.1:8471/about'), 'protocol-relative is external');
    }

    public function testResolveHandlesEveryReferenceForm(): void
    {
        $r = $this->rewriter();

        $this->assertSame('/about', $r->resolve('http://127.0.0.1:8471/about', '/'));
        $this->assertNull($r->resolve('https://example.com/x', '/'));
        $this->assertNull($r->resolve('//cdn.example.com/x.js', '/'));
        $this->assertNull($r->resolve('#top', '/'));
        $this->assertNull($r->resolve('mailto:a@b.c', '/'));
        $this->assertSame('/css/a.css', $r->resolve('/css/a.css', '/blog'));
        $this->assertSame('/post-1', $r->resolve('post-1', '/blog'), 'pages are served without a trailing slash');
        $this->assertSame('/blog/post-1', $r->resolve('post-1', '/blog/index'));
        $this->assertSame('/img/x.png', $r->resolve('../img/x.png', '/blog/post'));
        $this->assertSame('/userfiles/templates/t/img/a.png', $r->resolve('../img/a.png', '/userfiles/templates/t/css/style.css'));
        $this->assertSame('/blog?page=2', $r->resolve('?page=2', '/blog'));
    }

    public function testPageLinksBecomeRootRelativeDirectories(): void
    {
        $r = $this->rewriter();

        $this->assertSame('/', $r->rewriteUrl('http://127.0.0.1:8471/', '/', 'link'), 'home link is /');
        $this->assertSame('/about/', $r->rewriteUrl('http://127.0.0.1:8471/about', '/', 'link'));
        $this->assertSame('/about/', $r->rewriteUrl('/about/', '/', 'link'));
        $this->assertSame('/blog/page/2/', $r->rewriteUrl('/blog?current_page=2', '/', 'link'));
        $this->assertSame('/blog/page/2/?tag=x', $r->rewriteUrl('/blog?tag=x&page=2', '/', 'link'));
        $this->assertSame('/about/#team', $r->rewriteUrl('/about#team', '/', 'link'));
        $this->assertSame('https://example.com/', $r->rewriteUrl('https://example.com/', '/', 'link'));
    }

    public function testAliasesMapRedirectedPathsToCanonical(): void
    {
        $r = $this->rewriter();
        $r->setAliases(['/home' => '/']);

        $this->assertSame('/', $r->rewriteUrl('/home', '/', 'link'));
    }

    public function testMediaIsMappedToR2RouteOnlyWhenEnabled(): void
    {
        $r = $this->rewriter(true);
        $this->assertSame('/media/default/a.jpg', $r->rewriteUrl('http://127.0.0.1:8471/userfiles/media/default/a.jpg', '/', 'asset'));
        $this->assertSame([], $r->assets(), 'R2 media is not bundled');

        $r = $this->rewriter(false);
        $this->assertSame('/userfiles/media/default/a.jpg', $r->rewriteUrl('/userfiles/media/default/a.jpg', '/', 'asset'));
        $this->assertSame(['/userfiles/media/default/a.jpg'], $r->assets(), 'bundled at the same path without R2');
    }

    public function testDocumentRewriteCoversAttributesStylesAndMeta(): void
    {
        $html = '<!DOCTYPE html><html><head>'
            . '<link rel="canonical" href="http://127.0.0.1:8471/about">'
            . '<meta property="og:url" content="http://127.0.0.1:8471/about">'
            . '<meta property="og:image" content="http://127.0.0.1:8471/userfiles/media/og.png">'
            . '<link rel="stylesheet" href="http://127.0.0.1:8471/userfiles/templates/t/css/s.css">'
            . '<style>.a{background:url("http://127.0.0.1:8471/userfiles/templates/t/img/bg.png")}</style>'
            . '</head><body>'
            . '<a href="http://127.0.0.1:8471/">Home</a>'
            . '<a href="contact">Contact</a>'
            . '<img src="/userfiles/media/a.jpg" srcset="/userfiles/media/a.jpg 1x, http://127.0.0.1:8471/userfiles/media/a@2x.jpg 2x" data-src="/userfiles/media/lazy.jpg">'
            . '<picture><source srcset="/userfiles/media/a.webp"></picture>'
            . '<video poster="/userfiles/media/p.jpg"><source src="/userfiles/media/v.mp4"></video>'
            . '<form action="http://127.0.0.1:8471/contact"><input></form>'
            . '<div style="background: url(/userfiles/templates/t/img/x.png)"></div>'
            . '<a href="https://external.example/x">ext</a>'
            . '<img src="//cdn.example.com/y.png">'
            . '</body></html>';

        $doc = HtmlDocument::parse($html);
        $r = $this->rewriter(true, 'https://shop.example.com');
        $r->rewriteDocument($doc, '/about');
        $out = HtmlDocument::serialize($doc);

        $this->assertStringContainsString('<link rel="canonical" href="https://shop.example.com/about/">', $out);
        $this->assertStringContainsString('<meta property="og:url" content="https://shop.example.com/about/">', $out);
        $this->assertStringContainsString('<meta property="og:image" content="/media/og.png">', $out);
        $this->assertStringContainsString('href="/userfiles/templates/t/css/s.css"', $out);
        $this->assertStringContainsString('url("/userfiles/templates/t/img/bg.png")', $out);
        $this->assertStringContainsString('<a href="/">Home</a>', $out);
        $this->assertStringContainsString('<a href="/contact/">Contact</a>', $out);
        $this->assertStringContainsString('src="/media/a.jpg"', $out);
        $this->assertStringContainsString('srcset="/media/a.jpg 1x, /media/a@2x.jpg 2x"', $out);
        $this->assertStringContainsString('data-src="/media/lazy.jpg"', $out);
        $this->assertStringContainsString('<source srcset="/media/a.webp">', $out);
        $this->assertStringContainsString('poster="/media/p.jpg"', $out);
        $this->assertStringContainsString('<source src="/media/v.mp4">', $out);
        $this->assertStringContainsString('<form action="/contact/">', $out);
        $this->assertStringContainsString('url(/userfiles/templates/t/img/x.png)', $out);
        $this->assertStringContainsString('href="https://external.example/x"', $out);
        $this->assertStringContainsString('src="//cdn.example.com/y.png"', $out);

        $this->assertSame([
            '/userfiles/templates/t/css/s.css',
            '/userfiles/templates/t/img/bg.png',
            '/userfiles/templates/t/img/x.png',
        ], $r->assets());
    }

    public function testCssRewriteResolvesRelativeToStylesheet(): void
    {
        $r = $this->rewriter(true);
        $css = '@import "base.css"; .a{background:url(../img/a.png)} .b{background:url("data:image/png;base64,AAA")} '
            . '.c{background:url(http://127.0.0.1:8471/userfiles/media/m.png)} .d{src:url(https://fonts.example/f.woff2)}';

        $out = $r->rewriteCss($css, '/userfiles/templates/t/css/style.css');

        $this->assertStringContainsString('@import "/userfiles/templates/t/css/base.css"', $out);
        $this->assertStringContainsString('url(/userfiles/templates/t/img/a.png)', $out);
        $this->assertStringContainsString('url("data:image/png;base64,AAA")', $out);
        $this->assertStringContainsString('url(/media/m.png)', $out);
        $this->assertStringContainsString('url(https://fonts.example/f.woff2)', $out);
        $this->assertSame(['/userfiles/templates/t/css/base.css', '/userfiles/templates/t/img/a.png'], $r->assets());
    }

    public function testStructuredDataUsesPublicOrigin(): void
    {
        $json = '{"url":"http://127.0.0.1:8471/red-mug","image":"http:\/\/127.0.0.1:8471\/userfiles\/media\/a.png"}';

        $this->assertSame(
            '{"url":"https://shop.example.com/red-mug","image":"https:\/\/shop.example.com\/userfiles\/media\/a.png"}',
            $this->rewriter(false, 'https://shop.example.com')->rewriteStructuredData($json)
        );
        $this->assertSame(
            '{"url":"/red-mug","image":"\/userfiles\/media\/a.png"}',
            $this->rewriter()->rewriteStructuredData($json)
        );
    }

    public function testCanonicalIsRootRelativeWithoutPublicUrl(): void
    {
        $doc = HtmlDocument::parse('<html><head><link rel="canonical" href="http://127.0.0.1:8471/x"></head><body></body></html>');
        $this->rewriter()->rewriteDocument($doc, '/x');

        $this->assertStringContainsString('<link rel="canonical" href="/x/">', HtmlDocument::serialize($doc));
    }

    public function testUtf8SurvivesTheRoundTrip(): void
    {
        $doc = HtmlDocument::parse("<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Caf\u{e9}</title></head><body><p>na\u{ef}ve \u{2665}</p></body></html>");
        $this->rewriter()->rewriteDocument($doc, '/');

        $out = HtmlDocument::serialize($doc);
        $this->assertStringContainsString("<title>Caf\u{e9}</title>", $out);
        $this->assertStringContainsString("na\u{ef}ve \u{2665}", $out);
    }
}
