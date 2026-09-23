<?php

namespace FlareWeber\Compiler;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pure DOM transformations applied to pages captured from the Microweber
 * frontend: strip editor chrome and the Microweber runtime, mark the page as
 * FlareWeber generated, inject the store shim, derive layout-based pages
 * (thank-you, 404) and read the link graph. No framework dependencies.
 */
class HtmlCompiler
{
    public const GENERATOR = 'FlareWeber';

    private const CHROME_IDS = [
        'mw-admin-container', 'mw-live-edit-toolbar', 'mw-toolbar', 'mw-edit-toolbar', 'mw-live-edit',
        'mw-admin-panel', 'mw-quick-edit', 'mw-le-toolbar', 'mw-editor-toolbar', 'mw-live-edit-settings',
        'mw-livewire-component-iframe',
    ];

    private const CHROME_CLASSES = [
        'mw-live-edit', 'mw-edit-toolbar', 'mw-toolbar', 'mw-live-edit-toolbar', 'mw-admin', 'mw-admin-panel',
        'live-edit-overlay', 'edit-mode-toolbar', 'mw-le-toolbar', 'mw-editor-toolbar', 'mw-drag-helper',
        'mw-live-edit-settings', 'overlayEditor', 'mw-lightbox-editor',
    ];

    private const RUNTIME_SCRIPT_SRC = '#(/apijs_combined|/apijs(\?|$|/)|/api/|livewire|live[_-]?edit|/admin(/|$|-)|mw-admin|/editor\.js)#i';

    private const RUNTIME_SCRIPT_BODY = '#(\bmw\.settings\b|liveEdit|LiveEdit|live_edit|\bmw\.(require|lib|moduleCSS|reload_module|top|\$)\b|\bLivewire\b|window\.livewire|\bmw\.\$\()#';

    private const NAV_XPATH = '//nav//a[@href] | //*[@role="menu"]//a[@href] | //header//a[@href] | //*[@id="header"]//a[@href]'
        . ' | //*[contains(concat(" ", normalize-space(@class), " "), " module-menu ")]//a[@href]'
        . ' | //*[contains(concat(" ", normalize-space(@class), " "), " navbar ")]//a[@href]';

    private const CONTENT_XPATH = [
        '//main', '//*[@role="main"]', '//*[@id="content"]',
        '//*[contains(concat(" ", normalize-space(@class), " "), " edit ") and @rel="page" and @field="content"]',
        '//*[@id="main"]', '//body',
    ];

    /**
     * Convenience for tests and callers that hold a string.
     */
    public function cleanHtml(string $html): string
    {
        $doc = HtmlDocument::parse($html);
        $this->clean($doc);

        return HtmlDocument::serialize($doc);
    }

    private bool $coreStripped = false;

    /**
     * Remove Microweber admin/live-edit chrome and its runtime from a page.
     * Returns true when a Microweber core bundle (apijs_combined, which also
     * ships jQuery) was removed, so the caller can supply jQuery itself.
     */
    public function clean(DOMDocument $doc): bool
    {
        $this->coreStripped = false;
        $xpath = HtmlDocument::xpath($doc);

        foreach (self::CHROME_IDS as $id) {
            foreach ($xpath->query('//*[@id="' . $id . '"]') ?: [] as $node) {
                HtmlDocument::remove($node);
            }
        }

        foreach (self::CHROME_CLASSES as $class) {
            foreach (iterator_to_array($xpath->query('//*[' . HtmlDocument::classPredicate($class) . ']') ?: []) as $node) {
                HtmlDocument::remove($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*[@*[name()="wire:id"]]') ?: []) as $node) {
            HtmlDocument::remove($node);
        }

        foreach (iterator_to_array($xpath->query('//base') ?: []) as $node) {
            HtmlDocument::remove($node);
        }

        foreach (iterator_to_array($xpath->query('//script') ?: []) as $script) {
            if ($script instanceof DOMElement) {
                $this->cleanScript($script);
            }
        }

        foreach (iterator_to_array($xpath->query('//*[' . HtmlDocument::classPredicate('edit') . ' or @mw-edit or '
            . HtmlDocument::classPredicate('mw-edit') . ']') ?: []) as $el) {
            if ($el instanceof DOMElement) {
                $this->stripEditAttributes($el);
            }
        }

        foreach (iterator_to_array($xpath->query('//*[@onclick]') ?: []) as $el) {
            if ($el instanceof DOMElement) {
                $this->convertInlineHandler($el);
            }
        }

        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $comment) {
            if (!str_starts_with(trim($comment->textContent), '[if')) {
                HtmlDocument::remove($comment);
            }
        }

        $this->markGenerated($doc);

        return $this->coreStripped;
    }

    /**
     * Insert a synchronous vendor script (no defer) before the first script
     * in the document so template code that expects it at parse time works.
     */
    public function injectVendorScript(DOMDocument $doc, string $src): void
    {
        $script = $doc->createElement('script');
        $script->setAttribute('src', $src);

        $first = $doc->getElementsByTagName('script')->item(0);
        if ($first !== null && $first->parentNode !== null) {
            $first->parentNode->insertBefore($script, $first);

            return;
        }

        $head = HtmlDocument::head($doc);
        if ($head !== null) {
            $head->appendChild($script);
        }
    }

    /**
     * Replace Microweber's generator meta with FlareWeber's.
     */
    public function markGenerated(DOMDocument $doc): void
    {
        $xpath = HtmlDocument::xpath($doc);
        foreach (iterator_to_array($xpath->query('//meta[translate(@name, "GENERATOR", "generator")="generator"]') ?: []) as $meta) {
            HtmlDocument::remove($meta);
        }

        $head = HtmlDocument::head($doc);
        if ($head === null) {
            return;
        }

        $meta = $doc->createElement('meta');
        $meta->setAttribute('name', 'generator');
        $meta->setAttribute('content', self::GENERATOR);
        $head->insertBefore($meta, $head->firstChild);
    }

    /**
     * Append a raw HTML fragment (script tags) at the end of <body>.
     */
    public function injectScripts(DOMDocument $doc, string $fragment): void
    {
        HtmlDocument::appendToBody($doc, $fragment);
    }

    /**
     * Build a new page from an existing layout: keeps header/footer, swaps
     * the main content region for $bodyFragment and sets the title.
     */
    public function deriveFromLayout(DOMDocument $layout, string $title, string $bodyFragment): DOMDocument
    {
        $doc = HtmlDocument::parse(HtmlDocument::serialize($layout));
        $xpath = HtmlDocument::xpath($doc);

        $target = null;
        foreach (self::CONTENT_XPATH as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0 && $nodes->item(0) instanceof DOMElement) {
                $target = $nodes->item(0);
                break;
            }
        }

        if ($target instanceof DOMElement) {
            HtmlDocument::setInnerHtml($target, $bodyFragment);
            $this->clearSiblingContentRegions($xpath, $target);
        }

        foreach (iterator_to_array($xpath->query('//title') ?: []) as $node) {
            HtmlDocument::remove($node);
        }
        foreach (iterator_to_array($xpath->query('//link[@rel="canonical"] | //meta[@property="og:url"] | //meta[@property="og:title"]') ?: []) as $node) {
            HtmlDocument::remove($node);
        }
        foreach (iterator_to_array($xpath->query('//script[@type="application/ld+json"]') ?: []) as $node) {
            HtmlDocument::remove($node);
        }

        $head = HtmlDocument::head($doc);
        if ($head !== null) {
            $titleEl = $doc->createElement('title');
            $titleEl->textContent = $title;
            $head->appendChild($titleEl);
        }

        return $doc;
    }

    /**
     * Minimal standalone page used when no layout is available.
     */
    public function standalonePage(string $siteName, string $title, string $bodyFragment): string
    {
        $name = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="generator" content="' . self::GENERATOR . '">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $safeTitle . ' | ' . $name . '</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:48px 16px;max-width:720px;margin:auto;color:#111}</style>'
            . '</head><body>' . $bodyFragment . '</body></html>';
    }

    /**
     * Every anchor href in the document (raw attribute values).
     *
     * @return array<int, string>
     */
    public function links(DOMDocument $doc): array
    {
        $links = [];
        foreach (HtmlDocument::xpath($doc)->query('//a[@href] | //area[@href]') ?: [] as $a) {
            if ($a instanceof DOMElement) {
                $links[] = $a->getAttribute('href');
            }
        }

        return $links;
    }

    /**
     * Anchor hrefs that live inside navigation regions (menus, header).
     *
     * @return array<int, string>
     */
    public function navLinks(DOMDocument $doc): array
    {
        $links = [];
        foreach (HtmlDocument::xpath($doc)->query(self::NAV_XPATH) ?: [] as $a) {
            if ($a instanceof DOMElement) {
                $links[] = $a->getAttribute('href');
            }
        }

        return array_values(array_unique($links));
    }

    public function title(DOMDocument $doc): string
    {
        $node = $doc->getElementsByTagName('title')->item(0);

        return $node !== null ? trim($node->textContent) : '';
    }

    private function cleanScript(DOMElement $script): void
    {
        $src = $script->getAttribute('src');

        if ($src !== '' && (preg_match(self::RUNTIME_SCRIPT_SRC, $src) === 1 || $script->getAttribute('id') === 'mw-js-core-scripts')) {
            if (str_contains($src, 'apijs') || $script->getAttribute('id') === 'mw-js-core-scripts') {
                $this->coreStripped = true;
            }
            HtmlDocument::remove($script);

            return;
        }

        if ($src !== '') {
            return;
        }

        $type = strtolower($script->getAttribute('type'));
        if ($type !== '' && !in_array($type, ['text/javascript', 'module', 'application/javascript'], true)) {
            return;
        }

        $body = $script->textContent;
        if (preg_match(self::RUNTIME_SCRIPT_BODY, $body) !== 1) {
            return;
        }

        foreach ($this->stylesheetsRequiredBy($body) as $href) {
            $link = $script->ownerDocument?->createElement('link');
            if ($link === null) {
                continue;
            }
            $link->setAttribute('rel', 'stylesheet');
            $link->setAttribute('href', $href);
            $link->setAttribute('data-fw-synth', '1');
            $script->parentNode?->insertBefore($link, $script);
        }

        HtmlDocument::remove($script);
    }

    /**
     * CSS files an inline Microweber bootstrap script would have loaded
     * through mw.require()/mw.moduleCSS(); they become plain link tags.
     *
     * @return array<int, string>
     */
    private function stylesheetsRequiredBy(string $body): array
    {
        preg_match_all('#mw\.(?:require|moduleCSS)\(\s*["\']([^"\']+\.css(?:\?[^"\']*)?)["\']#', $body, $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /**
     * Microweber's add-to-cart buttons call mw.cart.add('.selector') inline;
     * the store shim binds them through data-fw-add-to-cart instead. Any
     * other handler that reaches into the (removed) mw runtime is dropped.
     */
    private function convertInlineHandler(DOMElement $el): void
    {
        $onclick = $el->getAttribute('onclick');

        if (preg_match('#mw\.cart\.add\(\s*["\']([^"\']+)["\']#', $onclick, $m) === 1) {
            $el->setAttribute('data-fw-add-to-cart', $m[1]);
            $el->removeAttribute('onclick');

            return;
        }

        if (preg_match('#mw\.cart\.add_item\(\s*["\']?(\d+)["\']?#', $onclick, $m) === 1) {
            $el->setAttribute('data-fw-add-to-cart-id', $m[1]);
            $el->removeAttribute('onclick');

            return;
        }

        if (preg_match('#\bmw\.#', $onclick) === 1) {
            $el->removeAttribute('onclick');
        }
    }

    private function stripEditAttributes(DOMElement $el): void
    {
        $remove = [];
        foreach ($el->attributes ?? [] as $attr) {
            $name = strtolower($attr->nodeName);
            if ($name === 'contenteditable' || str_starts_with($name, 'mw-')) {
                $remove[] = $attr->nodeName;
            }
        }

        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }

    private function clearSiblingContentRegions(\DOMXPath $xpath, DOMNode $keep): void
    {
        $query = self::CONTENT_XPATH[3];
        foreach (iterator_to_array($xpath->query($query) ?: []) as $region) {
            if ($region === $keep || !$region instanceof DOMElement) {
                continue;
            }
            if ($keep->parentNode === null || $this->contains($region, $keep) || $this->contains($keep, $region)) {
                continue;
            }
            HtmlDocument::setInnerHtml($region, '');
        }
    }

    private function contains(DOMNode $ancestor, DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p === $ancestor) {
                return true;
            }
        }

        return false;
    }
}
