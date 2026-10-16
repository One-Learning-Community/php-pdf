<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html\Css;

use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;

/**
 * The `<style>` blocks of a document and the cascade over them.
 *
 * Rules apply by specificity, then order; `!important` wins over normal
 * declarations; the `style` attribute beats normal rules. `@media print`
 * and `@media all` apply, other media (screen) are skipped; `@page` gives
 * the page size and margins. Other at-rules are ignored.
 *
 * @internal
 */
final class StyleSheet
{
    /** @var list<array{selector: Selector, declarations: array<string, array{0: string, 1: bool}>, order: int}> */
    private array $rules = [];

    /** @var array<string, array{0: string, 1: bool}> */
    private array $page = [];

    /** @var \WeakMap<\DOMElement, array<string, string>> */
    private \WeakMap $cache;

    public function __construct()
    {
        $this->cache = new \WeakMap;
    }

    public function add(string $css): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $this->parseBlock($css, true);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [] && $this->page === [];
    }

    private function parseBlock(string $css, bool $applies): void
    {
        $pos = 0;
        $len = strlen($css);
        while ($pos < $len) {
            $open = strpos($css, '{', $pos);
            if ($open === false) {
                return;
            }
            $prelude = trim(substr($css, $pos, $open - $pos));
            // A statement at-rule (@import, @charset) before the block.
            while (preg_match('/^@[a-z-]+[^;{]*;\s*/i', $prelude, $m) === 1) {
                $prelude = trim(substr($prelude, strlen($m[0])));
            }
            $close = self::matchingBrace($css, $open);
            $body = substr($css, $open + 1, $close - $open - 1);
            $pos = $close + 1;

            if (str_starts_with($prelude, '@')) {
                if (preg_match('/^@media\s+(.*)$/is', $prelude, $m) === 1) {
                    $this->parseBlock($body, $applies && self::mediaApplies($m[1]));
                } elseif (preg_match('/^@page\b/i', $prelude) === 1 && $applies) {
                    $this->page = array_merge($this->page, Declarations::parse($body));
                }

                continue; // @font-face, @keyframes, @supports …
            }
            if (! $applies) {
                continue;
            }
            $declarations = Declarations::parse($body);
            foreach (explode(',', $prelude) as $text) {
                $selector = Selector::parse($text);
                if ($selector !== null) {
                    $this->rules[] = ['selector' => $selector, 'declarations' => $declarations, 'order' => count($this->rules)];
                }
            }
        }
    }

    private static function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        $len = strlen($css);
        for ($i = $open; $i < $len; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return $len;
    }

    private static function mediaApplies(string $query): bool
    {
        foreach (explode(',', strtolower($query)) as $q) {
            $q = trim($q);
            if (preg_match('/^(only\s+)?(print|all)\b/', $q) === 1 || str_starts_with($q, '(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declarations that apply to an element: matching rules by the
     * cascade, then its `style` attribute.
     *
     * @return array<string, string>
     */
    public function declarationsFor(\DOMElement $element): array
    {
        if (isset($this->cache[$element])) {
            return $this->cache[$element];
        }

        $matched = [];
        foreach ($this->rules as $rule) {
            if ($rule['selector']->matches($element)) {
                $matched[] = $rule;
            }
        }
        usort($matched, static fn (array $a, array $b): int => [$a['selector']->specificity, $a['order']] <=> [$b['selector']->specificity, $b['order']]);

        $inline = Declarations::parse($element->getAttribute('style'));
        $normal = [];
        $important = [];
        foreach ($matched as $rule) {
            foreach ($rule['declarations'] as $name => [$value, $isImportant]) {
                if ($isImportant) {
                    $important[$name] = $value;
                } else {
                    $normal[$name] = $value;
                }
            }
        }
        foreach ($inline as $name => [$value, $isImportant]) {
            if ($isImportant) {
                $important[$name] = $value;
            } elseif (! isset($important[$name])) {
                $normal[$name] = $value;
            }
        }

        return $this->cache[$element] = array_merge($normal, $important);
    }

    /**
     * The page from `@page`: size (a named paper, `landscape`, or two
     * lengths) and margins. Null when the sheet has no `@page`.
     */
    public function pageSetup(): ?PageSetup
    {
        if ($this->page === []) {
            return null;
        }
        $get = fn (string $name): ?string => $this->page[$name][0] ?? null;

        $paper = PaperSize::A4;
        $orientation = Orientation::Portrait;
        $custom = null;
        $size = $get('size');
        if ($size !== null) {
            $lengths = [];
            foreach (Declarations::tokens($size) as $token) {
                $lower = strtolower($token);
                if ($lower === 'landscape') {
                    $orientation = Orientation::Landscape;
                } elseif (($named = self::paper($lower)) !== null) {
                    $paper = $named;
                } elseif (($len = Values::length($token)) !== null) {
                    $lengths[] = $len;
                }
            }
            if ($lengths !== []) {
                $custom = [$lengths[0], $lengths[1] ?? $lengths[0]];
            }
        }

        $margin = static fn (?string $v): float => $v !== null ? (Values::length($v) ?? 56.7) : 56.7;
        $margins = new PageMargins(
            topPt: $margin($get('margin-top')),
            rightPt: $margin($get('margin-right')),
            bottomPt: $margin($get('margin-bottom')),
            leftPt: $margin($get('margin-left')),
        );

        return new PageSetup(paperSize: $paper, orientation: $orientation, margins: $margins, customDimensionsPt: $custom);
    }

    private static function paper(string $name): ?PaperSize
    {
        return match ($name) {
            'a3' => PaperSize::A3,
            'a4' => PaperSize::A4,
            'a5' => PaperSize::A5,
            'a6' => PaperSize::A6,
            'letter' => PaperSize::Letter,
            'legal' => PaperSize::Legal,
            'ledger', 'tabloid' => PaperSize::Tabloid,
            default => null,
        };
    }
}
