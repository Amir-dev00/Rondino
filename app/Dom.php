<?php

declare(strict_types=1);

/** Lightweight HTML helper for verified CSS selectors used by adapters. */
final class Dom
{
    private DOMDocument $doc;
    private DOMXPath $xp;

    private function __construct(DOMDocument $doc)
    {
        $this->doc = $doc;
        $this->xp = new DOMXPath($doc);
    }

    public static function fromHtml(string $html): self
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8">' . $html;
        $doc->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        return new self($doc);
    }

    /** @return list<DOMElement> */
    public function css(string $selector, ?DOMNode $ctx = null): array
    {
        $xpath = self::cssToXPath($selector);
        $nodes = $ctx ? $this->xp->query($xpath, $ctx) : $this->xp->query($xpath);
        $out = [];
        if ($nodes) {
            foreach ($nodes as $n) {
                if ($n instanceof DOMElement) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    public function first(string $selector, ?DOMNode $ctx = null): ?DOMElement
    {
        $list = $this->css($selector, $ctx);
        return $list[0] ?? null;
    }

    /** @return list<DOMElement> */
    public function xpath(string $expression, ?DOMNode $ctx = null): array
    {
        $nodes = $ctx ? $this->xp->query($expression, $ctx) : $this->xp->query($expression);
        $out = [];
        if ($nodes) {
            foreach ($nodes as $n) {
                if ($n instanceof DOMElement) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    public function attr(DOMElement $el, string $name): ?string
    {
        if (!$el->hasAttribute($name)) {
            return null;
        }
        return $el->getAttribute($name);
    }

    public function text(?DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
    }

    public function html(DOMElement $el): string
    {
        $html = '';
        foreach ($el->childNodes as $child) {
            $html .= $this->doc->saveHTML($child);
        }
        return $html;
    }

    private static function cssToXPath(string $selector): string
    {
        $selector = trim($selector);
        // attribute contains: a[href*="/product/"]
        if (preg_match('/^([a-z0-9]+)?\[([a-z0-9_-]+)\*=\"([^\"]+)\"\]$/i', $selector, $m)) {
            $tag = $m[1] !== '' ? $m[1] : '*';
            return './/' . $tag . '[contains(@' . $m[2] . ',"' . $m[3] . '")]';
        }
        // tag.class1.class2
        if (preg_match('/^([a-z0-9]*)\.([a-z0-9_-]+(?:\.[a-z0-9_-]+)*)$/i', $selector, $m)) {
            $tag = $m[1] !== '' ? $m[1] : '*';
            $classes = explode('.', $m[2]);
            $pred = [];
            foreach ($classes as $c) {
                $pred[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
            }
            return './/' . $tag . '[' . implode(' and ', $pred) . ']';
        }
        // tag only
        if (preg_match('/^[a-z0-9]+$/i', $selector)) {
            return './/' . $selector;
        }
        // meta[itemprop="url"]
        if (preg_match('/^([a-z0-9]+)\[([a-z0-9_-]+)=\"([^\"]+)\"\]$/i', $selector, $m)) {
            return './/' . $m[1] . '[@' . $m[2] . '="' . $m[3] . '"]';
        }
        // a[href] — attribute exists
        if (preg_match('/^([a-z0-9]+)\[([a-z0-9_-]+)\]$/i', $selector, $m)) {
            return './/' . $m[1] . '[@' . $m[2] . ']';
        }
        throw new InvalidArgumentException('Unsupported CSS selector: ' . $selector);
    }
}
