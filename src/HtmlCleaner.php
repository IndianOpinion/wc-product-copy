<?php

declare(strict_types=1);

namespace IndianOpinion\WcProductCopy;

/**
 * Case 41: model output is untrusted. Markdown becomes HTML, then a DOM
 * pass drops dangerous elements with their content, turns headings into
 * paragraphs, unwraps links and every other tag not on the list, removes
 * attributes and contact details, and finally wp_kses() enforces the list.
 */
final class HtmlCleaner
{
    /** Elements removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript', 'head', 'title',
        'textarea', 'select', 'button', 'video', 'audio', 'canvas', 'img', 'picture', 'link', 'meta', 'base',
    ];

    private const BLOCKS = ['p', 'ul', 'ol', 'li', 'div', 'section', 'article', 'blockquote', 'table', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * @return array<string, array<string, bool>> Tags allowed in drafts, for wp_kses().
     */
    public static function allowedHtml(): array
    {
        $default = ['p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => []];
        /** @var mixed $filtered */
        $filtered = apply_filters('product_copy_allowed_html', $default);
        if (!is_array($filtered)) {
            return $default;
        }
        $out = [];
        foreach ($filtered as $tag => $attributes) {
            if (is_string($tag) && preg_match('/^[a-z][a-z0-9]*$/', $tag) === 1 && !in_array($tag, self::DROP, true) && $tag !== 'a') {
                // Attributes are never allowed in drafts.
                $out[$tag] = [];
            }
        }

        return $out;
    }

    /**
     * @return array{html: string, removed: list<string>}
     */
    public function clean(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/^\s*```[a-z]*\s*$/mi', '', $text);
        if (preg_match('#<\s*/?\s*[a-z!][^>]*>#i', $text) !== 1) {
            $text = self::markdown($text);
        }
        $allowed = self::allowedHtml();
        $removed = [];
        $html = $this->domPass($text, array_keys($allowed), $removed);
        $html = wp_kses($html, $allowed);
        $html = (string) preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
        $html = (string) preg_replace('#(<br\s*/?>\s*){3,}#i', '<br><br>', $html);

        return ['html' => trim($html), 'removed' => $removed];
    }

    /**
     * Markdown and plain text to HTML: bullets and numbered lines to lists,
     * headings and blank-line blocks to paragraphs, bold and italic markers
     * to strong and em.
     */
    public static function markdown(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n\s*\n/', trim($text)) ?: [] as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), static fn (string $l): bool => trim($l) !== ''));
            if ($lines === []) {
                continue;
            }
            $bullets = array_filter($lines, static fn (string $l): bool => preg_match('/^\s*[-*\x{2022}]\s+/u', $l) === 1);
            $numbers = array_filter($lines, static fn (string $l): bool => preg_match('/^\s*\d+[.)]\s+/', $l) === 1);
            if (count($bullets) === count($lines) || count($numbers) === count($lines)) {
                $tag = count($bullets) === count($lines) ? 'ul' : 'ol';
                $items = array_map(static fn (string $l): string => '<li>'
                    . self::inline((string) preg_replace('/^\s*(?:[-*\x{2022}]|\d+[.)])\s+/u', '', $l)) . '</li>', $lines);
                $out[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
                continue;
            }
            $paragraph = [];
            $list = [];
            foreach ($lines as $line) {
                $heading = preg_match('/^\s*#{1,6}\s+(.*)$/', $line, $h) === 1;
                $bullet = !$heading && preg_match('/^\s*[-*\x{2022}]\s+(.*)$/u', $line, $b) === 1;
                if (($heading || $bullet) && $paragraph !== []) {
                    $out[] = '<p>' . implode('<br>', $paragraph) . '</p>';
                    $paragraph = [];
                }
                if (!$bullet && $list !== []) {
                    $out[] = '<ul>' . implode('', $list) . '</ul>';
                    $list = [];
                }
                if ($heading) {
                    $out[] = '<p>' . self::inline($h[1]) . '</p>';
                } elseif ($bullet) {
                    $list[] = '<li>' . self::inline($b[1]) . '</li>';
                } else {
                    $paragraph[] = self::inline(trim($line));
                }
            }
            if ($paragraph !== []) {
                $out[] = '<p>' . implode('<br>', $paragraph) . '</p>';
            }
            if ($list !== []) {
                $out[] = '<ul>' . implode('', $list) . '</ul>';
            }
        }

        return implode("\n", $out);
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\*\w])\*(?=\S)(.+?)(?<=\S)\*(?![\*\w])/u', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/u', '<em>$1</em>', $text);
        // A Markdown link keeps its text only.
        return (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text);
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $removed
     */
    private function domPass(string $html, array $allowed, array &$removed): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return self::removeContacts($html, $removed);
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="product-copy-root">'
            . $html . '</div></body></html>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $loaded ? $doc->getElementById('product-copy-root') : null;
        if (!$root instanceof \DOMElement) {
            return self::removeContacts(strip_tags($html), $removed);
        }
        $this->walk($root, $allowed, $removed);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= (string) $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $removed
     */
    private function walk(\DOMNode $node, array $allowed, array &$removed): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                $child->nodeValue = self::removeContacts((string) $child->nodeValue, $removed);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            $this->walk($child, $allowed, $removed);
            if (preg_match('/^h[1-6]$/', $tag) === 1 && !in_array($tag, $allowed, true) && in_array('p', $allowed, true)) {
                $child = $this->rename($child, 'p');
                $tag = 'p';
            }
            if (!in_array($tag, $allowed, true)) {
                $this->unwrap($child, in_array($tag, self::BLOCKS, true));
                continue;
            }
            while ($child->attributes->length > 0) {
                $attribute = $child->attributes->item(0);
                if ($attribute === null) {
                    break;
                }
                $child->removeAttribute($attribute->nodeName);
            }
        }
    }

    private function rename(\DOMElement $element, string $tag): \DOMElement
    {
        $doc = $element->ownerDocument;
        if (!$doc instanceof \DOMDocument || $element->parentNode === null) {
            return $element;
        }
        $new = $doc->createElement($tag);
        while ($element->firstChild !== null) {
            $new->appendChild($element->firstChild);
        }
        $element->parentNode->replaceChild($new, $element);

        return $new;
    }

    private function unwrap(\DOMElement $element, bool $block): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }
        $doc = $element->ownerDocument;
        if ($block && $doc instanceof \DOMDocument) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        if ($block && $doc instanceof \DOMDocument) {
            $parent->insertBefore($doc->createTextNode(' '), $element);
        }
        $parent->removeChild($element);
    }

    /**
     * Remove URLs, email addresses and phone-number-like strings.
     *
     * @param list<string> $removed
     */
    public static function removeContacts(string $text, array &$removed): string
    {
        $patterns = [
            'email' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            'url' => '#\b(?:https?://|ftp://|www\.)[^\s<>"]+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|net|org|io|co|shop|store|info|biz|uk|in|de|fr|app|dev)(?:/[^\s<>"]*)?\b#iu',
            'phone' => '/(?<![\w+])\+?\(?\d[\d\s().\-]{7,}\d(?!\w)/u',
        ];
        foreach ($patterns as $kind => $pattern) {
            $text = (string) preg_replace_callback($pattern, static function (array $m) use ($kind, &$removed): string {
                $match = $m[0];
                if ($kind === 'phone') {
                    $digits = (int) preg_match_all('/\d/', $match);
                    if ($digits < 9 || $digits > 15 || preg_match('/^\d{4}\s*[-\x{2013}]\s*\d{4}$/u', trim($match)) === 1) {
                        return $match;
                    }
                }
                $removed[] = $kind . ':' . mb_substr(trim($match), 0, 80);

                return '';
            }, $text);
        }

        return $removed === [] ? $text : (string) preg_replace('/ {2,}/', ' ', $text);
    }
}
