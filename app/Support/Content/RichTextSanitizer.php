<?php

namespace App\Support\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

class RichTextSanitizer
{
    private const ALLOWED_TAGS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'br', 'blockquote'];

    public function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="pc-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('pc-root');
        if (! $root) {
            return '';
        }
        $this->sanitizeChildren($root);

        $sanitized = '';
        foreach ($root->childNodes as $child) {
            $sanitized .= $document->saveHTML($child);
        }

        return $sanitized;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $tag = mb_strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($child);

                continue;
            }
            $this->sanitizeAttributes($child, $tag);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            if ($tag !== 'a' || ! in_array($attribute->name, ['href', 'title'], true)) {
                $element->removeAttribute($attribute->name);
            }
        }
        if ($tag === 'a' && ! SafeUrl::isAllowed($element->getAttribute('href'))) {
            $element->removeAttribute('href');
        }
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent) {
            return;
        }
        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
        $this->sanitizeChildren($parent);
    }
}
