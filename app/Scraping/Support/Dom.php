<?php

namespace App\Scraping\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

final class Dom
{
    public DOMDocument $document;

    public DOMXPath $xpath;

    public function __construct(string $html, ?string $charset = 'UTF-8')
    {
        $this->document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        $wrapped = $html;
        if (! str_contains(mb_strtolower($html), '<html')) {
            $wrapped = '<!DOCTYPE html><html><head><meta charset="'.$charset.'"></head><body>'.$html.'</body></html>';
        } elseif (! str_contains(mb_strtolower(substr($html, 0, 500)), 'charset')) {
            $wrapped = preg_replace('/<head([^>]*)>/i', '<head$1><meta charset="'.$charset.'">', $html, 1) ?? $html;
        }

        $this->document->loadHTML('<?xml encoding="'.$charset.'">'.$wrapped, LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xpath = new DOMXPath($this->document);
    }

    public static function fromHtml(string $html): self
    {
        return new self($html);
    }

    /**
     * @return list<DOMElement>
     */
    public function css(string $selector, ?DOMNode $context = null): array
    {
        $xpath = $this->cssToXPath($selector);
        if ($context) {
            // Relative to context — leading // would re-search the whole document
            $xpath = '.'.(str_starts_with($xpath, '/') ? $xpath : '//'.$xpath);
        }
        $nodes = $context
            ? $this->xpath->query($xpath, $context)
            : $this->xpath->query($xpath);

        return $this->elements($nodes);
    }

    /**
     * @return list<DOMElement>
     */
    public function query(string $xpath, ?DOMNode $context = null): array
    {
        if ($context && ! str_starts_with($xpath, '.')) {
            $xpath = '.'.(str_starts_with($xpath, '/') ? $xpath : '//'.$xpath);
        }
        $nodes = $context
            ? $this->xpath->query($xpath, $context)
            : $this->xpath->query($xpath);

        return $this->elements($nodes);
    }

    public function first(string $selector, ?DOMNode $context = null): ?DOMElement
    {
        $all = $this->css($selector, $context);

        return $all[0] ?? null;
    }

    public function text(?DOMNode $node): string
    {
        if (! $node) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
    }

    public function attr(?DOMNode $node, string $name): ?string
    {
        if (! $node instanceof DOMElement) {
            return null;
        }

        if (! $node->hasAttribute($name)) {
            return null;
        }

        return $node->getAttribute($name);
    }

    public function html(?DOMNode $node): string
    {
        if (! $node) {
            return '';
        }

        return $this->document->saveHTML($node) ?: '';
    }

    public static function absoluteUrl(string $base, string $href): string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')) {
            return $base;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $origin = $scheme.'://'.$host.$port;

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = $parts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        if ($dir === '.' || $dir === '\\') {
            $dir = '';
        }

        return $origin.$dir.'/'.$href;
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMNodeList|false|null $nodes): array
    {
        if (! $nodes) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private function cssToXPath(string $selector): string
    {
        // Minimal CSS→XPath for tag.class / .class / tag[attr] / tag[attr*=] / descendant spaces
        $parts = preg_split('/\s+/', trim($selector)) ?: [];
        $xpathParts = [];
        foreach ($parts as $part) {
            if ($part === '>') {
                continue;
            }
            $xpathParts[] = $this->simpleCssToXPath($part);
        }

        return '//'.implode('//', $xpathParts);
    }

    private function simpleCssToXPath(string $selector): string
    {
        $tag = '*';
        $predicates = [];

        if (preg_match('/^([a-zA-Z0-9]+)/', $selector, $m)) {
            $tag = $m[1];
            $selector = substr($selector, strlen($m[1]));
        }

        if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $classes)) {
            foreach ($classes[1] as $class) {
                $predicates[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
            }
        }

        if (preg_match_all('/\[([a-zA-Z0-9_-]+)(?:([*^$|]?)=[\'"]([^\'"]*)[\'"])?\]/', $selector, $attrs, PREG_SET_ORDER)) {
            foreach ($attrs as $attr) {
                $name = $attr[1];
                if (! isset($attr[3])) {
                    $predicates[] = '@'.$name;
                    continue;
                }
                $op = $attr[2] ?? '';
                $val = $attr[3];
                $predicates[] = match ($op) {
                    '*' => 'contains(@'.$name.', "'.$val.'")',
                    '^' => 'starts-with(@'.$name.', "'.$val.'")',
                    default => '@'.$name.'="'.$val.'"',
                };
            }
        }

        if (preg_match('/#([a-zA-Z0-9_-]+)/', $selector, $id)) {
            $predicates[] = '@id="'.$id[1].'"';
        }

        if ($predicates === []) {
            return $tag;
        }

        return $tag.'['.implode(' and ', $predicates).']';
    }
}
