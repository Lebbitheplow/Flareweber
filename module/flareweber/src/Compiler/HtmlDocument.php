<?php

namespace FlareWeber\Compiler;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Thin libxml wrapper: HTML5-tolerant parsing with UTF-8 preserved on the
 * way in and out, plus the few DOM helpers the compiler needs.
 */
final class HtmlDocument
{
    public static function parse(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $previous = libxml_use_internal_errors(true);

        // The XML prolog forces libxml to read the source as UTF-8 regardless
        // of (missing) meta charset declarations; it is removed after load.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $doc->removeChild($node);
            }
        }
        $doc->encoding = 'UTF-8';

        return $doc;
    }

    /**
     * Serialize as UTF-8. Dumping the document element (not the document)
     * keeps non-ASCII characters raw instead of turning them into entities;
     * the doctype is re-emitted by hand.
     */
    public static function serialize(DOMDocument $doc): string
    {
        $root = $doc->documentElement;
        if ($root === null) {
            $html = $doc->saveHTML();

            return $html === false ? '' : $html;
        }

        $doctype = $doc->doctype !== null ? '<!DOCTYPE ' . ($doc->doctype->name ?: 'html') . ">\n" : '';
        $html = $doc->saveHTML($root);

        return $doctype . ($html === false ? '' : $html) . "\n";
    }

    public static function xpath(DOMDocument $doc): DOMXPath
    {
        return new DOMXPath($doc);
    }

    /**
     * XPath predicate matching a whitespace separated class token.
     */
    public static function classPredicate(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";
    }

    public static function hasClass(DOMElement $el, string $class): bool
    {
        $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];

        return in_array($class, $classes, true);
    }

    public static function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }

    /**
     * Replace the children of $node with the given HTML fragment.
     */
    public static function setInnerHtml(DOMElement $node, string $fragment): void
    {
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }

        $doc = $node->ownerDocument;
        if ($doc === null) {
            return;
        }

        $tmp = self::parse('<div id="fw-fragment-root">' . $fragment . '</div>');
        $root = $tmp->getElementById('fw-fragment-root');
        if ($root === null) {
            return;
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            $node->appendChild($doc->importNode($child, true));
        }
    }

    public static function remove(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }

    public static function head(DOMDocument $doc): ?DOMElement
    {
        $head = $doc->getElementsByTagName('head')->item(0);

        return $head instanceof DOMElement ? $head : null;
    }

    public static function body(DOMDocument $doc): ?DOMElement
    {
        $body = $doc->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $body : null;
    }

    /**
     * Append raw HTML (e.g. script tags) to the end of <body>.
     */
    public static function appendToBody(DOMDocument $doc, string $fragment): void
    {
        $body = self::body($doc);
        if ($body === null) {
            return;
        }

        $tmp = self::parse('<div id="fw-fragment-root">' . $fragment . '</div>');
        $root = $tmp->getElementById('fw-fragment-root');
        if ($root === null) {
            return;
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            $body->appendChild($doc->importNode($child, true));
        }
    }
}
