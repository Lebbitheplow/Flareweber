<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Compiler\HtmlCompiler;
use FlareWeber\Compiler\HtmlDocument;
use PHPUnit\Framework\TestCase;

class HtmlCompilerTest extends TestCase
{
    private function compiler(): HtmlCompiler
    {
        return new HtmlCompiler();
    }

    public function testStripsLiveEditChromeAndRuntimeScripts(): void
    {
        $html = '<!DOCTYPE html><html><head>'
            . '<meta name="generator" content="Microweber">'
            . '<script src="http://127.0.0.1:8471/apijs_combined?mwv=2.0.20" id="mw-js-core-scripts"></script>'
            . '<script src="/userfiles/cache/livewire/livewire.js"></script>'
            . '<script>window.livewire = new Livewire();</script>'
            . '<script>mw.require("http://127.0.0.1:8471/userfiles/modules/microweber/css/ui.css"); mw.lib.require("bootstrap3");</script>'
            . '<script src="/userfiles/templates/default/js/default.js"></script>'
            . '<base href="http://127.0.0.1:8471/">'
            . '</head><body>'
            . '<div id="mw-admin-container">admin</div>'
            . '<div class="mw-live-edit-toolbar">toolbar</div>'
            . '<div class="edit" rel="page" field="content" contenteditable="true" mw-edit-region="1" data-id="7"><p>Hello</p></div>'
            . '<div wire:id="abc" wire:initial-data="{}">modal</div>'
            . '<span class="unselectable" contenteditable="false">2026</span>'
            . '<div data-type="menu" data-item-id="4">keep data attrs</div>'
            . '<!-- mw_start --><!-- plain comment --><!--[if IE]><p>ie</p><![endif]-->'
            . '<script>$(document).ready(function(){ $.ajax({url: mw.settings.api_url + "pingstats"}); });</script>'
            . '<script type="application/ld+json">{"@type":"WebPage"}</script>'
            . '</body></html>';

        $out = $this->compiler()->cleanHtml($html);

        $this->assertStringContainsString('<meta name="generator" content="FlareWeber">', $out);
        $this->assertStringNotContainsString('content="Microweber"', $out);
        $this->assertStringNotContainsString('apijs_combined', $out);
        $this->assertStringNotContainsString('livewire', $out);
        $this->assertStringNotContainsString('Livewire', $out);
        $this->assertStringNotContainsString('mw.lib.require', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="http://127.0.0.1:8471/userfiles/modules/microweber/css/ui.css" data-fw-synth="1">', $out);
        $this->assertStringContainsString('src="/userfiles/templates/default/js/default.js"', $out, 'template scripts stay');
        $this->assertStringNotContainsString('<base', $out);
        $this->assertStringNotContainsString('mw-admin-container', $out);
        $this->assertStringNotContainsString('toolbar', $out);
        $this->assertStringNotContainsString('wire:id', $out);
        $this->assertStringContainsString('<div class="edit" rel="page" field="content" data-id="7"><p>Hello</p></div>', $out);
        $this->assertStringContainsString('contenteditable="false">2026</span>', $out, 'attributes outside .edit are untouched');
        $this->assertStringContainsString('data-type="menu" data-item-id="4"', $out);
        $this->assertStringNotContainsString('mw_start', $out);
        $this->assertStringNotContainsString('plain comment', $out);
        $this->assertStringContainsString('<!--[if IE]>', $out, 'conditional comments survive');
        $this->assertStringNotContainsString('pingstats', $out);
        $this->assertStringContainsString('application/ld+json', $out);
    }

    public function testCleanReportsStrippedCoreBundleAndVendorScriptGoesBeforeTemplateScripts(): void
    {
        $c = $this->compiler();

        $doc = HtmlDocument::parse('<html><head>'
            . '<script src="http://127.0.0.1:8471/apijs_combined?mwv=2.0.20" id="mw-js-core-scripts"></script>'
            . '<link rel="stylesheet" href="/userfiles/templates/default/css/style.css">'
            . '<script src="/userfiles/templates/default/js/default.js"></script>'
            . '</head><body><script>console.log(1)</script></body></html>');
        $this->assertTrue($c->clean($doc), 'core bundle was stripped');

        $c->injectVendorScript($doc, '/fw/vendor/jquery.min.js');
        $out = HtmlDocument::serialize($doc);
        $this->assertMatchesRegularExpression(
            '#<script src="/fw/vendor/jquery\.min\.js"></script><script src="/userfiles/templates/default/js/default\.js"></script>#',
            $out,
            'jQuery is inserted synchronously right before the first template script'
        );
        $this->assertStringNotContainsString('defer', $out);
        $this->assertSame(1, substr_count($out, 'jquery.min.js'));

        $plain = HtmlDocument::parse('<html><head><title>x</title></head><body><script src="/userfiles/templates/t/app.js"></script></body></html>');
        $this->assertFalse($c->clean($plain), 'nothing stripped, nothing to replace');

        $noScripts = HtmlDocument::parse('<html><head><title>x</title></head><body></body></html>');
        $c->injectVendorScript($noScripts, '/fw/vendor/jquery.min.js');
        $this->assertStringContainsString('<head><title>x</title><script src="/fw/vendor/jquery.min.js"></script></head>', HtmlDocument::serialize($noScripts));
    }

    public function testInlineCartHandlersBecomeDataAttributes(): void
    {
        $out = $this->compiler()->cleanHtml('<html><body>'
            . '<button type="button" onclick="mw.cart.add(\'.mw-add-to-cart-141\');">Add</button>'
            . '<a href="#" onclick="mw.tools.open(1)">x</a>'
            . '<a href="#" onclick="return confirm(\'sure?\')">y</a>'
            . '</body></html>');

        $this->assertStringContainsString('<button type="button" data-fw-add-to-cart=".mw-add-to-cart-141">Add</button>', $out);
        $this->assertStringContainsString('<a href="#">x</a>', $out);
        $this->assertStringContainsString('onclick="return confirm(\'sure?\')"', $out, 'unrelated handlers are kept');
    }

    public function testLinksAndNavLinks(): void
    {
        $doc = HtmlDocument::parse('<html><body>'
            . '<div id="header"><ul role="menu"><li><a href="/about">About</a></li></ul></div>'
            . '<nav><a href="/shop">Shop</a></nav>'
            . '<p><a href="/blog/post-1">Read</a> <a href="https://x.example">ext</a></p>'
            . '</body></html>');

        $c = $this->compiler();
        $this->assertSame(['/about', '/shop', '/blog/post-1', 'https://x.example'], $c->links($doc));
        $this->assertSame(['/about', '/shop'], $c->navLinks($doc));
    }

    public function testDeriveFromLayoutSwapsContentRegionAndTitle(): void
    {
        $layout = HtmlDocument::parse('<!DOCTYPE html><html><head><title>Home</title>'
            . '<link rel="canonical" href="/"><meta property="og:url" content="/"></head><body>'
            . '<header><a href="/">Brand</a></header>'
            . '<div class="edit" rel="page" field="content"><h1>Welcome</h1><p>Home stuff</p></div>'
            . '<div class="edit" rel="page" field="content"><p>Second region</p></div>'
            . '<footer>Footer</footer></body></html>');

        $doc = $this->compiler()->deriveFromLayout($layout, 'Thank you', '<div id="fw-order-summary"></div>');
        $out = HtmlDocument::serialize($doc);

        $this->assertStringContainsString('<title>Thank you</title>', $out);
        $this->assertStringContainsString('<header><a href="/">Brand</a></header>', $out);
        $this->assertStringContainsString('<footer>Footer</footer>', $out);
        $this->assertStringContainsString('<div id="fw-order-summary"></div>', $out);
        $this->assertStringNotContainsString('Home stuff', $out);
        $this->assertStringNotContainsString('Second region', $out);
        $this->assertStringNotContainsString('canonical', $out);
        $this->assertStringContainsString('<title>Home</title>', HtmlDocument::serialize($layout), 'layout is not mutated');
    }

    public function testInjectScriptsAppendsToBody(): void
    {
        $doc = HtmlDocument::parse('<html><head></head><body><p>x</p></body></html>');
        $this->compiler()->injectScripts($doc, '<script>window.FW_FEATURES={"ecommerce":true};</script><script src="/fw/store.js" defer></script>');

        $out = HtmlDocument::serialize($doc);
        $this->assertMatchesRegularExpression('#<p>x</p><script>window\.FW_FEATURES=\{"ecommerce":true\};</script><script src="/fw/store\.js" defer(="")?></script></body>#', $out);
    }

    public function testStandalonePageEscapesAndMarksGenerator(): void
    {
        $out = $this->compiler()->standalonePage('A & B', 'Not <found>', '<h1>x</h1>');

        $this->assertStringContainsString('<meta name="generator" content="FlareWeber">', $out);
        $this->assertStringContainsString('<title>Not &lt;found&gt; | A &amp; B</title>', $out);
        $this->assertStringContainsString('<h1>x</h1>', $out);
    }
}
