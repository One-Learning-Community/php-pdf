<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Forms\FieldTree;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfNull;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfStream;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfValueSerializer;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;

/**
 * What changed between a signed revision and the file as it is now — the
 * defence against incremental-saving and shadow attacks, where content is
 * changed after signing while the signature itself stays intact.
 *
 * Every object that is new or differs is classified: signatures, document
 * timestamps and the DSS are "signing"; field values and their appearances
 * "form filling"; annotations "annotations"; a changed page, content stream,
 * resource or anything not recognised is "other" — never allowed after a
 * signature. New supporting objects (appearance streams, fonts, DSS streams)
 * are judged through what refers to them.
 *
 * @internal
 */
final class RevisionDiff
{
    /** Catalog entries that may change after a signature. */
    private const CATALOG_SIGNING = ['AcroForm', 'DSS', 'Perms', 'Extensions', 'Metadata'];

    /** Field entries a form filler changes. */
    private const FIELD_FILLING = ['V', 'AP', 'AS', 'MK', 'NeedAppearances'];

    /** Signature field entries that change when it is signed. */
    private const SIGNATURE_FIELD = ['V', 'AP', 'AS', 'Lock', 'F', 'MK'];

    public ModificationLevel $level = ModificationLevel::None;

    /** @var list<string> */
    public array $changes = [];

    /** @var list<string> fully qualified names of fields whose value changed */
    public array $changedFields = [];

    private readonly ReaderDocument $old;

    private readonly ReaderDocument $new;

    private readonly PdfValueSerializer $encoder;

    public function __construct(string $pdf, int $revisionEnd, #[\SensitiveParameter] string $password = '')
    {
        $this->old = ReaderDocument::fromBytes(substr($pdf, 0, $revisionEnd), $password);
        $this->new = ReaderDocument::fromBytes($pdf, $password);
        $this->encoder = new PdfValueSerializer;
        $this->analyse();
    }

    private function analyse(): void
    {
        $fieldNames = [];
        $signatureFields = [];
        foreach ((new FieldTree)->build($this->new) as $name => $node) {
            foreach ([$node->fieldObjNum, ...$node->widgetObjNums] as $num) {
                $fieldNames[$num] = $name;
                if ($node->type === 'signature') {
                    $signatureFields[$num] = true;
                }
            }
        }
        $pageNums = [];
        $annotArrays = [];
        foreach ($this->new->pages() as $page) {
            $pageNums[$page->objectNumber] = true;
            $annots = $page->dict->get('Annots');
            if ($annots instanceof PdfReference) {
                $annotArrays[$annots->number] = $page->objectNumber;
            }
        }
        $rootRef = $this->new->trailer()->get('Root');
        $catalogNum = $rootRef instanceof PdfReference ? $rootRef->number : -1;
        $acroRef = $this->new->catalog()->get('AcroForm');
        $acroNum = $acroRef instanceof PdfReference ? $acroRef->number : -1;
        $acro = $this->new->deref($acroRef);
        $fieldsRef = $acro instanceof PdfDictionary ? $acro->get('Fields') : null;
        $fieldsArrayNum = $fieldsRef instanceof PdfReference ? $fieldsRef->number : -1;
        $oldNumbers = array_flip($this->old->objectNumbers());

        foreach ($this->new->objectNumbers() as $num) {
            $now = $this->new->getObject($num);
            $existed = isset($oldNumbers[$num]);
            $before = $existed ? $this->old->getObject($num) : PdfNull::instance();
            if ($existed && $this->encode($before) === $this->encode($now)) {
                continue;
            }
            $dict = $now instanceof PdfStream ? $now->dict : $now;
            $type = $dict instanceof PdfDictionary ? self::name($dict->get('Type')) : null;

            // Cross-reference and object streams are the update's own plumbing.
            if ($type === 'XRef' || $type === 'ObjStm') {
                continue;
            }
            if ($type === 'Sig' || $type === 'DocTimeStamp' || $type === 'DSS') {
                $this->record(ModificationLevel::Signing, $existed ? "$type $num replaced" : "$type $num added");

                continue;
            }
            if ($num === $catalogNum) {
                $this->classifyDict($before, $now, self::CATALOG_SIGNING, ModificationLevel::Signing, 'catalog');

                continue;
            }
            if ($num === $acroNum) {
                $this->classifyDict($before, $now, ['Fields', 'SigFlags', 'NeedAppearances', 'DR', 'DA'], ModificationLevel::Signing, 'AcroForm');

                continue;
            }
            if ($num === $fieldsArrayNum) {
                $this->record(ModificationLevel::Signing, 'AcroForm fields extended');

                continue;
            }
            if (isset($signatureFields[$num])) {
                $existed
                    ? $this->classifyDict($before, $now, self::SIGNATURE_FIELD, ModificationLevel::Signing, "signature field {$fieldNames[$num]}")
                    : $this->record(ModificationLevel::Signing, "signature field {$fieldNames[$num]} added");

                continue;
            }
            if (isset($fieldNames[$num])) {
                if (! $existed) {
                    $this->record(ModificationLevel::Other, "form field {$fieldNames[$num]} added");

                    continue;
                }
                if ($this->classifyDict($before, $now, self::FIELD_FILLING, ModificationLevel::FormFilling, "field {$fieldNames[$num]}")) {
                    $this->changedFields[] = $fieldNames[$num];
                }

                continue;
            }
            if (isset($pageNums[$num])) {
                $this->classifyPage($before, $now, $num);

                continue;
            }
            if (isset($annotArrays[$num])) {
                $this->classifyAnnotations(is_array($before) ? $before : [], is_array($now) ? $now : [], "page {$annotArrays[$num]}");

                continue;
            }
            if ($type === 'Annot') {
                $this->record(ModificationLevel::Annotations, $existed ? "annotation $num changed" : "annotation $num added");

                continue;
            }
            if (! $existed) {
                // A new supporting object (appearance stream, font, DSS
                // stream): judged through whatever refers to it.
                continue;
            }
            $this->record(ModificationLevel::Other, sprintf('object %d%s changed', $num, $type !== null ? " ($type)" : ''));
        }
        $this->changedFields = array_values(array_unique($this->changedFields));
    }

    /**
     * Records the level of a changed dictionary: `$allowed` keys at
     * `$level`, any other key as "other". Returns whether anything changed.
     *
     * @param  list<string>  $allowed
     */
    private function classifyDict(mixed $before, mixed $now, array $allowed, ModificationLevel $level, string $what): bool
    {
        $old = self::items($before);
        $new = self::items($now);
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);
        $changed = [];
        foreach ($keys as $key) {
            $a = isset($old[$key]) ? $this->encode($old[$key]) : null;
            $b = isset($new[$key]) ? $this->encode($new[$key]) : null;
            if ($a !== $b) {
                $changed[] = (string) $key;
            }
        }
        if ($changed === []) {
            return false;
        }
        $disallowed = array_diff($changed, $allowed);
        $this->record($disallowed === [] ? $level : ModificationLevel::Other, "$what: ".implode(', ', $changed));

        return true;
    }

    private function classifyPage(mixed $before, mixed $now, int $num): void
    {
        $old = self::items($before);
        $new = self::items($now);
        $other = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if ($key === 'Annots') {
                continue;
            }
            if ((isset($old[$key]) ? $this->encode($old[$key]) : null) !== (isset($new[$key]) ? $this->encode($new[$key]) : null)) {
                $other[] = (string) $key;
            }
        }
        if ($other !== []) {
            $this->record(ModificationLevel::Other, "page $num: ".implode(', ', $other));
        }
        $this->classifyAnnotations(
            (array) $this->old->deref($old['Annots'] ?? []),
            (array) $this->new->deref($new['Annots'] ?? []),
            "page $num",
        );
    }

    /**
     * @param  array<mixed>  $before
     * @param  array<mixed>  $now
     */
    private function classifyAnnotations(array $before, array $now, string $where): void
    {
        $oldRefs = array_map(fn (mixed $r): string => $this->encode($r), $before);
        foreach ($now as $ref) {
            if (in_array($this->encode($ref), $oldRefs, true)) {
                continue;
            }
            $annot = $this->new->deref($ref);
            $subtype = $annot instanceof PdfDictionary ? self::name($annot->get('Subtype')) : null;
            $ft = $annot instanceof PdfDictionary ? self::name($this->inheritedFt($annot)) : null;
            $this->record(
                $subtype === 'Widget' && $ft === 'Sig' ? ModificationLevel::Signing : ($subtype === 'Widget' ? ModificationLevel::Other : ModificationLevel::Annotations),
                "$where: ".($subtype ?? 'annotation').' added',
            );
        }
        if (count($now) < count($before)) {
            $this->record(ModificationLevel::Annotations, "$where: annotation removed");
        }
    }

    private function inheritedFt(PdfDictionary $dict): mixed
    {
        for ($i = 0; $i < 16 && $dict instanceof PdfDictionary; $i++) {
            if ($dict->get('FT') !== null) {
                return $dict->get('FT');
            }
            $dict = $this->new->deref($dict->get('Parent'));
        }

        return null;
    }

    private function record(ModificationLevel $level, string $change): void
    {
        $this->changes[] = $change;
        if ($level->value > $this->level->value) {
            $this->level = $level;
        }
    }

    private function encode(mixed $value): string
    {
        return $this->encoder->encode($value);
    }

    /** @return array<string, mixed> */
    private static function items(mixed $value): array
    {
        $dict = $value instanceof PdfStream ? $value->dict : $value;

        return $dict instanceof PdfDictionary ? $dict->all() : [];
    }

    private static function name(mixed $value): ?string
    {
        return $value instanceof PdfName ? $value->value : null;
    }
}
