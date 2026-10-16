<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Html\Css;

/**
 * One complex selector — `div.policy > table td.lbl` — matched right to
 * left against DOM elements.
 *
 * Supported: type, `*`, `.class`, `#id`, `[attr]`, `[attr=value]` (also
 * `~=`, `^=`, `$=`, `*=`), `:first-child`, `:last-child`, `:only-child`,
 * `:nth-child(n|odd|even|an+b)`, `:not(simple)`, and the combinators
 * descendant, `>`, `+`, `~`. A selector with anything else (hover, pseudo
 * elements, …) matches nothing.
 *
 * @internal
 */
final class Selector
{
    /**
     * Compounds right to left; each but the first carries the combinator
     * that joins it to the one before (to its right in the source).
     *
     * @param  list<array{compound: array<string, mixed>, combinator: string}>  $parts
     */
    private function __construct(
        private readonly array $parts,
        public readonly int $specificity,
    ) {}

    public static function parse(string $selector): ?self
    {
        $selector = trim((string) preg_replace('/\s*([>+~])\s*/', ' $1 ', trim($selector)));
        if ($selector === '') {
            return null;
        }
        $tokens = preg_split('/\s+/', $selector) ?: [];

        $parts = [];
        $combinator = ' ';
        $specificity = 0;
        foreach ($tokens as $token) {
            if (in_array($token, ['>', '+', '~'], true)) {
                $combinator = $token;

                continue;
            }
            $compound = self::compound($token);
            if ($compound === null) {
                return null;
            }
            $specificity += $compound['specificity'];
            $parts[] = ['compound' => $compound, 'combinator' => $combinator];
            $combinator = ' ';
        }
        if ($parts === []) {
            return null;
        }

        // Right to left: the subject first; each part keeps the combinator
        // that links it to the part on its right.
        $reversed = [];
        $count = count($parts);
        for ($i = $count - 1; $i >= 0; $i--) {
            $reversed[] = [
                'compound' => $parts[$i]['compound'],
                'combinator' => $i + 1 < $count ? $parts[$i + 1]['combinator'] : '',
            ];
        }

        return new self($reversed, $specificity);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function compound(string $token): ?array
    {
        $c = ['tag' => null, 'id' => null, 'classes' => [], 'attrs' => [], 'pseudos' => [], 'specificity' => 0];
        if (preg_match('/^(\*|[a-z][a-z0-9-]*)/i', $token, $m) === 1) {
            if ($m[1] !== '*') {
                $c['tag'] = strtolower($m[1]);
                $c['specificity'] += 1;
            }
            $token = substr($token, strlen($m[1]));
        }
        while ($token !== '') {
            if (preg_match('/^#([\w-]+)/u', $token, $m) === 1) {
                $c['id'] = $m[1];
                $c['specificity'] += 10000;
            } elseif (preg_match('/^\.([\w-]+)/u', $token, $m) === 1) {
                $c['classes'][] = $m[1];
                $c['specificity'] += 100;
            } elseif (preg_match('/^\[\s*([\w-]+)\s*(?:([~^$*|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]/u', $token, $m) === 1) {
                $c['attrs'][] = [strtolower($m[1]), $m[2] ?? '', ($m[3] ?? '') !== '' ? $m[3] : (($m[4] ?? '') !== '' ? $m[4] : ($m[5] ?? ''))];
                $c['specificity'] += 100;
            } elseif (preg_match('/^:not\(([^)]*)\)/i', $token, $m) === 1) {
                $inner = self::compound(trim($m[1]));
                if ($inner === null) {
                    return null;
                }
                $c['pseudos'][] = ['not', $inner];
                $c['specificity'] += $inner['specificity'];
            } elseif (preg_match('/^:(first-child|last-child|only-child)/i', $token, $m) === 1) {
                $c['pseudos'][] = [strtolower($m[1]), null];
                $c['specificity'] += 100;
            } elseif (preg_match('/^:nth-child\(\s*([^)]*)\)/i', $token, $m) === 1) {
                $ab = self::nth($m[1]);
                if ($ab === null) {
                    return null;
                }
                $c['pseudos'][] = ['nth-child', $ab];
                $c['specificity'] += 100;
            } else {
                return null; // hover, ::before, … — never matches on paper
            }
            $token = substr($token, strlen($m[0]));
        }

        return $c;
    }

    /**
     * @return array{0: int, 1: int}|null a, b of an+b
     */
    private static function nth(string $expr): ?array
    {
        $expr = strtolower(str_replace(' ', '', $expr));

        return match (true) {
            $expr === 'odd' => [2, 1],
            $expr === 'even' => [2, 0],
            preg_match('/^[+-]?\d+$/', $expr) === 1 => [0, (int) $expr],
            preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $expr, $m) === 1 => [
                $m[1] === '' || $m[1] === '+' ? 1 : ($m[1] === '-' ? -1 : (int) $m[1]),
                (int) ($m[2] ?? 0),
            ],
            default => null,
        };
    }

    public function matches(\DOMElement $element): bool
    {
        return $this->matchFrom($element, 0);
    }

    private function matchFrom(\DOMElement $element, int $index): bool
    {
        $part = $this->parts[$index];
        if (! self::matchesCompound($element, $part['compound'])) {
            return false;
        }
        if ($index === count($this->parts) - 1) {
            return true;
        }
        $next = $index + 1;

        return match ($part['combinator']) {
            '>' => ($parent = self::parentElement($element)) !== null && $this->matchFrom($parent, $next),
            '+' => ($prev = self::previousElement($element)) !== null && $this->matchFrom($prev, $next),
            '~' => $this->anyPrevious($element, $next),
            default => $this->anyAncestor($element, $next),
        };
    }

    private function anyAncestor(\DOMElement $element, int $index): bool
    {
        for ($a = self::parentElement($element); $a !== null; $a = self::parentElement($a)) {
            if ($this->matchFrom($a, $index)) {
                return true;
            }
        }

        return false;
    }

    private function anyPrevious(\DOMElement $element, int $index): bool
    {
        for ($p = self::previousElement($element); $p !== null; $p = self::previousElement($p)) {
            if ($this->matchFrom($p, $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private static function matchesCompound(\DOMElement $el, array $c): bool
    {
        if ($c['tag'] !== null && strtolower($el->nodeName) !== $c['tag']) {
            return false;
        }
        if ($c['id'] !== null && $el->getAttribute('id') !== $c['id']) {
            return false;
        }
        if ($c['classes'] !== []) {
            $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
            foreach ($c['classes'] as $class) {
                if (! in_array($class, $classes, true)) {
                    return false;
                }
            }
        }
        foreach ($c['attrs'] as [$name, $op, $value]) {
            if (! $el->hasAttribute($name)) {
                return false;
            }
            $actual = $el->getAttribute($name);
            $ok = match ($op) {
                '' => true,
                '=' => $actual === $value,
                '~=' => in_array($value, preg_split('/\s+/', $actual) ?: [], true),
                '^=' => $value !== '' && str_starts_with($actual, $value),
                '$=' => $value !== '' && str_ends_with($actual, $value),
                '*=' => $value !== '' && str_contains($actual, $value),
                '|=' => $actual === $value || str_starts_with($actual, $value.'-'),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }
        foreach ($c['pseudos'] as [$kind, $arg]) {
            $ok = match ($kind) {
                'not' => ! self::matchesCompound($el, $arg),
                'first-child' => self::previousElement($el) === null,
                'last-child' => self::nextElement($el) === null,
                'only-child' => self::previousElement($el) === null && self::nextElement($el) === null,
                'nth-child' => self::nthMatches(self::position($el), $arg[0], $arg[1]),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function nthMatches(int $pos, int $a, int $b): bool
    {
        if ($a === 0) {
            return $pos === $b;
        }
        $n = ($pos - $b) / $a;

        return $n >= 0 && floor($n) === $n;
    }

    private static function position(\DOMElement $el): int
    {
        $pos = 1;
        for ($p = self::previousElement($el); $p !== null; $p = self::previousElement($p)) {
            $pos++;
        }

        return $pos;
    }

    private static function parentElement(\DOMElement $el): ?\DOMElement
    {
        $parent = $el->parentNode;

        // The parser's synthetic root wraps the input; it is not part of it.
        return $parent instanceof \DOMElement && $parent->getAttribute('id') !== '_phppdf_root' ? $parent : null;
    }

    private static function previousElement(\DOMElement $el): ?\DOMElement
    {
        for ($n = $el->previousSibling; $n !== null; $n = $n->previousSibling) {
            if ($n instanceof \DOMElement) {
                return $n;
            }
        }

        return null;
    }

    private static function nextElement(\DOMElement $el): ?\DOMElement
    {
        for ($n = $el->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof \DOMElement) {
                return $n;
            }
        }

        return null;
    }
}
