<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Validation;

use Dskripchenko\PhpPdf\Pdf\Forms\FieldTree;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Der;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\LtvException;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\RevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;

/**
 * Checks the signatures and document timestamps of a PDF.
 *
 * For each one: the signed byte range is well formed and its gap is exactly
 * the signature (guarding against signature-wrapping and universal-forgery
 * tricks); the CMS signature and the signer's certificate binding verify;
 * a signature timestamp covers this signature value; the signer chains to a
 * trusted root, at the timestamped time when there is one; OCSP responses or
 * CRLs from the DSS (or fetched) say it was not revoked; and what was
 * appended after it (incremental-saving and shadow attacks) is only what the
 * signature — or a certification — allows.
 *
 * ```php
 * $report = SignatureValidator::fromBytes($pdf)->validate(TrustStore::fromPem('root.crt'));
 * $report->allValid();
 * foreach ($report->signatures as $s) { $s->fieldName; $s->isValid(); $s->errors; }
 * ```
 */
final class SignatureValidator
{
    private readonly ReaderDocument $doc;

    private function __construct(
        private readonly string $pdf,
        #[\SensitiveParameter] private readonly string $password = '',
    ) {
        $this->doc = ReaderDocument::fromBytes($pdf, $password);
    }

    /**
     * @param  string  $password  For an encrypted PDF.
     */
    public static function fromBytes(string $pdf, #[\SensitiveParameter] string $password = ''): self
    {
        return new self($pdf, $password);
    }

    /**
     * @param  ?TrustStore  $trust  Trust anchors; none — nothing is trusted, but the rest is still checked.
     * @param  ?RevocationSource  $revocation  Fetch revocation data missing from the DSS (network).
     * @param  ?\DateTimeImmutable  $now  Validation time; defaults to now.
     */
    public function validate(?TrustStore $trust = null, ?RevocationSource $revocation = null, ?\DateTimeImmutable $now = null): ValidationReport
    {
        $trust ??= new TrustStore;
        $now ??= new \DateTimeImmutable;
        [$dssCerts, $dssOcsps, $dssCrls] = $this->dss();

        $entries = $this->signatureEntries();
        $reports = [];
        foreach ($entries as $entry) {
            $reports[] = $this->check($entry, $trust, $revocation, $now, $dssCerts, $dssOcsps, $dssCrls);
        }

        // A later verified document timestamp vouches for the time of every
        // earlier signature: chain validity is judged then, not now.
        foreach ($reports as $i => $report) {
            if ($report->kind !== 'signature' || $report->timestampTime !== null) {
                continue;
            }
            foreach (array_slice($reports, $i + 1) as $later) {
                if ($later->kind === 'timestamp' && $later->isValid() && $later->timestampTime !== null) {
                    $report->warn('No signature timestamp; a later document timestamp vouches only for existence before '.$later->timestampTime->format('c'));

                    break;
                }
            }
        }
        usort($reports, static fn (SignatureReport $a, SignatureReport $b): int => $a->signedRevisionLength <=> $b->signedRevisionLength);

        return new ValidationReport($reports);
    }

    /**
     * The document exactly as it was when the field was signed.
     */
    public function signedRevision(string $fieldName): string
    {
        foreach ($this->signatureEntries() as $entry) {
            if ($entry['name'] === $fieldName) {
                $range = $entry['byteRange'];
                if ($range !== null) {
                    return substr($this->pdf, 0, $range[2] + $range[3]);
                }
            }
        }
        throw new \InvalidArgumentException(sprintf('No signed field named "%s"', $fieldName));
    }

    /**
     * @param  array{name: string, sigNum: int, dict: PdfDictionary, byteRange: ?array{int, int, int, int}}  $entry
     * @param  list<Certificate>  $dssCerts
     * @param  list<string>  $dssOcsps
     * @param  list<string>  $dssCrls
     */
    private function check(array $entry, TrustStore $trust, ?RevocationSource $revocation, \DateTimeImmutable $now, array $dssCerts, array $dssOcsps, array $dssCrls): SignatureReport
    {
        $dict = $entry['dict'];
        $type = self::name($dict->get('Type'));
        $kind = $type === 'DocTimeStamp' ? 'timestamp' : 'signature';
        $subFilter = self::name($dict->get('SubFilter'));
        $range = $entry['byteRange'];
        $report = new SignatureReport($entry['name'], $kind, $subFilter, $range !== null ? $range[2] + $range[3] : 0);

        // 1. The byte range and the gap.
        $content = $this->checkByteRange($report, $range, $entry['sigNum']);
        if ($content === null) {
            return $report;
        }
        [$signedBytes, $der] = $content;

        // 2. The cryptography.
        try {
            $cms = SignedDataReader::parse($der);
        } catch (\Throwable $e) {
            $report->error('The signature is not a readable CMS: '.$e->getMessage());

            return $report;
        }
        $checks = new CmsChecks($trust, [...$cms->certificates(), ...$dssCerts], $now, $dssOcsps, $dssCrls);
        if ($kind === 'timestamp') {
            $checks->timestampToken($report, $cms, $signedBytes, isDocument: true);
            $report->claimedSigningTime = $report->timestampTime;
        } else {
            $this->checkSignature($report, $cms, $signedBytes, $dict, $checks);
        }

        // 3. Revocation of the signer's chain.
        if ($report->trusted && $report->chain !== []) {
            $checks->revocation($report, $revocation);
        }

        // 4. What came after.
        $this->checkModifications($report, $dict, $entry['sigNum']);

        return $report;
    }

    /**
     * @param  ?array{int, int, int, int}  $range
     * @return ?array{0: string, 1: string} signed bytes, DER
     */
    private function checkByteRange(SignatureReport $report, ?array $range, int $sigNum): ?array
    {
        $length = strlen($this->pdf);
        if ($range === null) {
            $report->error('The signature has no valid /ByteRange');

            return null;
        }
        [$a, $b, $c, $d] = $range;
        if ($a !== 0 || $b <= 0 || $c <= $b || $d < 0 || $c + $d > $length) {
            $report->error(sprintf('/ByteRange [%d %d %d %d] does not describe this file', $a, $b, $c, $d));

            return null;
        }
        // The gap must be exactly the /Contents hex string, nothing more.
        $gap = substr($this->pdf, $b, $c - $b);
        if (! preg_match('/^<([0-9A-Fa-f]*)>$/', $gap, $m) || strlen($m[1]) % 2 !== 0) {
            $report->error('The bytes left out of the signature are not just the signature value');

            return null;
        }
        $revision = substr($this->pdf, 0, $c + $d);
        if (! preg_match('/%%EOF\s*$/', $revision)) {
            $report->error('The signed bytes do not end at a revision boundary (%%EOF)');

            return null;
        }
        // The signature dictionary of the signed revision itself must carry
        // this value — otherwise /Contents was swapped in a later update.
        try {
            $signedDict = ReaderDocument::fromBytes($revision, $this->password)->getObject($sigNum);
        } catch (\Throwable) {
            $signedDict = null;
        }
        $contents = $signedDict instanceof PdfDictionary ? $signedDict->get('Contents') : null;
        $bytes = (string) hex2bin($m[1]);
        if (! $contents instanceof PdfString || rtrim($contents->bytes, "\x00") !== rtrim($bytes, "\x00")) {
            $report->error('The signature in the signed revision differs from the bytes left out of the range');

            return null;
        }
        $report->intact = true;
        $report->coversWholeFile = rtrim(substr($this->pdf, $c + $d)) === '';

        try {
            $der = DerReader::parseBer($bytes)->der;
        } catch (\Throwable) {
            $der = $bytes;
        }

        return [substr($this->pdf, $a, $b).substr($this->pdf, $c, $d), $der];
    }

    private function checkSignature(SignatureReport $report, SignedDataReader $cms, string $signedBytes, PdfDictionary $dict, CmsChecks $checks): void
    {
        // The claimed time is /M; read it first so revocation can use it.
        $m = $dict->get('M');
        $report->claimedSigningTime = $m instanceof PdfString ? self::pdfDate($m->bytes) : null;
        $checks->signature($report, $cms, $signedBytes, essExpected: $report->subFilter === 'ETSI.CAdES.detached');
    }

    private function checkModifications(SignatureReport $report, PdfDictionary $dict, int $sigNum): void
    {
        // A certification signature is the one /Perms names.
        $perms = $this->doc->deref($this->doc->catalog()->get('Perms'));
        $docMdp = $perms instanceof PdfDictionary ? $perms->get('DocMDP') : null;
        if ($docMdp instanceof PdfReference && $docMdp->number === $sigNum) {
            $report->certificationLevel = PdfSigner::docMdpLevel($this->doc, $dict);
        }
        if (! $report->intact || $report->coversWholeFile) {
            return;
        }
        try {
            $diff = new RevisionDiff($this->pdf, $report->signedRevisionLength, $this->password);
        } catch (\Throwable $e) {
            $report->error('The changes after signing cannot be analysed: '.$e->getMessage());

            return;
        }
        $report->modifications = $diff->level;
        if ($diff->level === ModificationLevel::Other) {
            $report->error('The document was changed after signing: '.implode('; ', $diff->changes));
        } elseif ($report->certificationLevel !== null && ! $diff->level->allowedBy($report->certificationLevel)) {
            $report->error(sprintf('The certification allows less than what changed after it: %s', implode('; ', $diff->changes)));
        }

        // FieldMDP: locked fields must not change.
        $locked = $this->lockedFields($dict);
        if ($locked !== null) {
            [$action, $fields] = $locked;
            foreach ($diff->changedFields as $field) {
                $isLocked = match ($action) {
                    'All' => true,
                    'Include' => in_array($field, $fields, true),
                    default => ! in_array($field, $fields, true),
                };
                if ($isLocked) {
                    $report->error(sprintf('Field "%s" is locked by this signature but changed after it', $field));
                }
            }
        }
    }

    /**
     * @return ?array{0: string, 1: list<string>}
     */
    private function lockedFields(PdfDictionary $dict): ?array
    {
        $refs = $this->doc->deref($dict->get('Reference'));
        foreach (is_array($refs) ? $refs : [] as $ref) {
            $ref = $this->doc->deref($ref);
            if (! $ref instanceof PdfDictionary || self::name($ref->get('TransformMethod')) !== 'FieldMDP') {
                continue;
            }
            $params = $this->doc->deref($ref->get('TransformParams'));
            if (! $params instanceof PdfDictionary) {
                continue;
            }
            $fields = [];
            foreach ((array) $this->doc->deref($params->get('Fields')) as $f) {
                $f = $this->doc->deref($f);
                if ($f instanceof PdfString) {
                    $fields[] = self::textString($f->bytes);
                }
            }

            return [self::name($params->get('Action')) ?? 'All', $fields];
        }

        return null;
    }

    /**
     * Signed signature fields: name, signature dictionary, byte range.
     *
     * @return list<array{name: string, sigNum: int, dict: PdfDictionary, byteRange: ?array{int, int, int, int}}>
     */
    private function signatureEntries(): array
    {
        $out = [];
        foreach ((new FieldTree)->build($this->doc) as $name => $node) {
            if ($node->type !== 'signature') {
                continue;
            }
            $field = $this->doc->getObject($node->fieldObjNum);
            $v = $field instanceof PdfDictionary ? $field->get('V') : null;
            $dict = $this->doc->deref($v);
            if (! $v instanceof PdfReference || ! $dict instanceof PdfDictionary) {
                continue;
            }
            $br = $this->doc->deref($dict->get('ByteRange'));
            $range = is_array($br) && count($br) === 4 && array_filter($br, 'is_int') === $br ? array_values($br) : null;
            $out[] = ['name' => $name, 'sigNum' => $v->number, 'dict' => $dict, 'byteRange' => $range !== null ? [$range[0], $range[1], $range[2], $range[3]] : null];
        }

        return $out;
    }

    /**
     * @return array{0: list<Certificate>, 1: list<string>, 2: list<string>}
     */
    private function dss(): array
    {
        $dss = $this->doc->deref($this->doc->catalog()->get('DSS'));
        if (! $dss instanceof PdfDictionary) {
            return [[], [], []];
        }
        $read = function (string $key) use ($dss): array {
            $out = [];
            foreach ((array) $this->doc->deref($dss->get($key)) as $ref) {
                $stream = $this->doc->deref($ref);
                if ($stream instanceof \Dskripchenko\PhpPdf\Pdf\Reader\PdfStream) {
                    $out[] = $this->doc->streamData($stream);
                }
            }

            return $out;
        };
        $certs = [];
        foreach ($read('Certs') as $der) {
            try {
                $certs[] = Certificate::fromDer($der);
            } catch (\InvalidArgumentException) {
            }
        }

        return [$certs, $read('OCSPs'), $read('CRLs')];
    }

    private static function name(mixed $value): ?string
    {
        return $value instanceof PdfName ? $value->value : null;
    }

    private static function textString(string $bytes): string
    {
        return str_starts_with($bytes, "\xFE\xFF") ? (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE') : $bytes;
    }

    /** `D:YYYYMMDDHHmmSS+HH'mm'` → time. */
    private static function pdfDate(string $date): ?\DateTimeImmutable
    {
        if (! preg_match("/^D:(\d{4})(\d{2})?(\d{2})?(\d{2})?(\d{2})?(\d{2})?([Zz+\-])?(\d{2})?'?(\d{2})?/", $date, $m)) {
            return null;
        }
        $tz = 'Z';
        if (isset($m[7]) && ($m[7] === '+' || $m[7] === '-')) {
            $tz = $m[7].($m[8] ?? '00').':'.($m[9] ?? '00');
        }
        $at = \DateTimeImmutable::createFromFormat(
            'YmdHisP',
            $m[1].($m[2] ?? '01').($m[3] ?? '01').($m[4] ?? '00').($m[5] ?? '00').($m[6] ?? '00').($tz === 'Z' ? '+00:00' : $tz),
        );

        return $at === false ? null : $at;
    }
}
