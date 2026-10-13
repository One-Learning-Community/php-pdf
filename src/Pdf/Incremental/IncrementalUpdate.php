<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Incremental;

use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfValueSerializer;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Writer;

/**
 * An incremental update (ISO 32000-1 §7.5.6): changes written after the end
 * of an existing file, which stays byte for byte as it was — so signatures
 * already in it keep covering exactly what they signed.
 *
 * New objects are numbered from the file's /Size up; replaced objects keep
 * their number and generation. The new cross-reference section follows the
 * kind of the file's last one — a classic table, or a cross-reference
 * stream — and chains to it with /Prev.
 *
 * Encrypted files are refused: their new objects would have to be
 * encrypted with the file key.
 */
final class IncrementalUpdate
{
    private readonly ReaderDocument $doc;

    private readonly Writer $allocator;

    /** @var array<int, string> replaced object number → body */
    private array $replaced = [];

    private readonly int $size;

    private readonly int $prevXref;

    private readonly bool $xrefStream;

    private function __construct(private readonly string $original)
    {
        $this->doc = ReaderDocument::fromBytes($original);
        $trailer = $this->doc->trailer();
        if ($trailer->has('Encrypt')) {
            throw new \InvalidArgumentException('Incremental updates of encrypted PDFs are not supported');
        }
        $size = $trailer->get('Size');
        if (! is_int($size) || $size < 1) {
            throw new \InvalidArgumentException('The PDF trailer has no valid /Size');
        }
        $this->size = $size;

        $pos = strrpos($original, 'startxref');
        if ($pos === false || ! preg_match('/\G\s*(\d+)/', $original, $m, 0, $pos + 9)) {
            throw new \InvalidArgumentException('The PDF has no startxref');
        }
        $this->prevXref = (int) $m[1];
        // The section the file points to: a classic table starts with
        // `xref`, a stream is an object (`N G obj`).
        $this->xrefStream = ! str_starts_with(ltrim(substr($original, $this->prevXref, 16)), 'xref');

        $this->allocator = new Writer(firstObjectId: $size);
    }

    public static function fromBytes(string $pdf): self
    {
        return new self($pdf);
    }

    /** The document as it stands before this update. */
    public function document(): ReaderDocument
    {
        return $this->doc;
    }

    /**
     * Allocates objects for the update — fonts and images register through
     * it as through any writer.
     */
    public function writer(): Writer
    {
        return $this->allocator;
    }

    /** A new object number. Fill it with {@see writer()}`->setObject()`. */
    public function allocate(): int
    {
        return $this->allocator->reserveObject();
    }

    public function add(string $body): int
    {
        return $this->allocator->addObject($body);
    }

    /** Replaces an existing object with a parsed value, re-serialised. */
    public function replace(int $number, mixed $value): void
    {
        $this->replaceBody($number, (new PdfValueSerializer)->encode($value));
    }

    /** Replaces an existing object with a body in PDF syntax. */
    public function replaceBody(int $number, string $body): void
    {
        if ($number < 1 || $number >= $this->size) {
            throw new \InvalidArgumentException("Object $number is not in the file");
        }
        $this->replaced[$number] = $body;
    }

    /** The parsed value of an object as the update would leave it, without the update's own changes. */
    public function object(int $number): mixed
    {
        return $this->doc->getObject($number);
    }

    /** Serialises a parsed value as the update writes it. */
    public static function encode(mixed $value): string
    {
        return (new PdfValueSerializer)->encode($value);
    }

    public function toBytes(): string
    {
        $objects = $this->replaced + $this->allocator->objectBodies();
        ksort($objects);

        $out = $this->original;
        if (! str_ends_with($out, "\n") && ! str_ends_with($out, "\r")) {
            $out .= "\n";
        }

        $offsets = [];
        foreach ($objects as $number => $body) {
            $gen = isset($this->replaced[$number]) ? $this->generation($number) : 0;
            $offsets[$number] = [strlen($out), $gen];
            $out .= sprintf("%d %d obj\n%s\nendobj\n", $number, $gen, $body);
        }

        $trailer = $this->doc->trailer();
        $nextSize = $objects === [] ? $this->size : max($this->size, max(array_keys($objects)) + 1);
        $extra = '';
        foreach (['Root', 'Info'] as $key) {
            $value = $trailer->get($key);
            if ($value instanceof PdfReference) {
                $extra .= sprintf(' /%s %d %d R', $key, $value->number, $value->generation);
            }
        }
        $extra .= ' /ID '.$this->id($objects);

        if ($this->xrefStream) {
            $out .= $this->xrefStreamSection($out, $offsets, $nextSize, $extra);
        } else {
            $xrefOffset = strlen($out);
            $out .= "xref\n".$this->classicEntries($offsets);
            $out .= sprintf("trailer\n<< /Size %d /Prev %d%s >>\nstartxref\n%d\n%%%%EOF\n", $nextSize, $this->prevXref, $extra, $xrefOffset);
        }

        return $out;
    }

    /**
     * @param  array<int, array{int, int}>  $offsets
     */
    private function classicEntries(array $offsets): string
    {
        $out = '';
        foreach (self::runs(array_keys($offsets)) as [$first, $count]) {
            $out .= "$first $count\n";
            for ($n = $first; $n < $first + $count; $n++) {
                [$offset, $gen] = $offsets[$n];
                $out .= sprintf("%010d %05d n \n", $offset, $gen);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{int, int}>  $offsets
     */
    private function xrefStreamSection(string $out, array $offsets, int $size, string $extra): string
    {
        // The stream lists itself too.
        $selfNumber = $size;
        $selfOffset = strlen($out);
        $offsets[$selfNumber] = [$selfOffset, 0];
        ksort($offsets);

        $index = [];
        $data = '';
        foreach (self::runs(array_keys($offsets)) as [$first, $count]) {
            $index[] = "$first $count";
            for ($n = $first; $n < $first + $count; $n++) {
                [$offset, $gen] = $offsets[$n];
                // Type 1: in-file object — W [1 4 2].
                $data .= "\x01".pack('N', $offset).pack('n', $gen);
            }
        }
        $compressed = (string) gzcompress($data, 6);

        return sprintf(
            "%d 0 obj\n<< /Type /XRef /Size %d /Prev %d%s /W [1 4 2] /Index [%s] /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream\nendobj\nstartxref\n%d\n%%%%EOF\n",
            $selfNumber, $size + 1, $this->prevXref, $extra, implode(' ', $index), strlen($compressed), $compressed, $selfOffset,
        );
    }

    /**
     * Contiguous runs of object numbers, as xref subsections.
     *
     * @param  list<int>  $numbers
     * @return list<array{int, int}> first number, count
     */
    private static function runs(array $numbers): array
    {
        sort($numbers);
        $runs = [];
        foreach ($numbers as $n) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last][0] + $runs[$last][1] === $n) {
                $runs[$last][1]++;
            } else {
                $runs[] = [$n, 1];
            }
        }

        return $runs;
    }

    private function generation(int $number): int
    {
        return $this->doc->generationOf($number);
    }

    /**
     * /ID: the first element stays (it names the document), the second
     * changes with every revision (§14.4).
     *
     * @param  array<int, string>  $objects
     */
    private function id(array $objects): string
    {
        $id = $this->doc->trailer()->get('ID');
        $first = is_array($id) && ($id[0] ?? null) instanceof PdfString ? $id[0]->bytes : md5($this->original, true);
        $second = md5($this->original.implode('', $objects), true);

        return sprintf('[<%s> <%s>]', bin2hex($first), bin2hex($second));
    }

    /** The trailer's catalog reference. */
    public function rootReference(): PdfReference
    {
        $root = $this->doc->trailer()->get('Root');
        if (! $root instanceof PdfReference) {
            throw new \InvalidArgumentException('The PDF trailer has no /Root reference');
        }

        return $root;
    }

    /** Parsed catalog dictionary. */
    public function catalog(): PdfDictionary
    {
        return $this->doc->catalog();
    }
}
