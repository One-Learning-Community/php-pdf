<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Forms\FieldTree;
use Dskripchenko\PhpPdf\Pdf\Incremental\IncrementalUpdate;
use Dskripchenko\PhpPdf\Pdf\Incremental\UpdateResources;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfReference;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfString;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\AppearanceRenderer;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\StampValues;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalMode;
use Dskripchenko\PhpPdf\Pdf\Signature\External\PreparedSignature;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\SignedDataReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\CertificateFetcher;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\LtvException;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\OnlineRevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\RevocationSource;
use Dskripchenko\PhpPdf\Pdf\Signature\Ltv\ValidationData;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

/**
 * Signs a PDF that already exists — made elsewhere, or signed before.
 *
 * The signature goes in as an incremental update: the original bytes stay
 * untouched, so earlier signatures remain valid and a second, third…
 * signer can follow. The field signed is, in order:
 *
 * 1. `SignatureConfig::$fieldName` — a field added with {@see addField()}
 *    or an empty signature field already in the document;
 * 2. a field added with {@see addField()};
 * 3. the first empty signature field of the document;
 * 4. a new invisible signature field on the first page.
 *
 * A certification signature (`SignatureConfig::$certification`) is only
 * possible on a document with no signatures yet. After it, signing is
 * limited to the empty signature fields already there — lay them out with
 * {@see addEmptyField()} in the certifying update — and a document
 * certified with "no changes" refuses further signatures.
 */
final class PdfSigner
{
    /** @var array{name: string, page: int, rect: array{float, float, float, float}, appearance: ?SignatureAppearance, font: ?PdfFont, boldFont: ?PdfFont}|null */
    private ?array $newField = null;

    /** @var list<array{name: string, page: int, rect: array{float, float, float, float}, appearance: ?SignatureAppearance, font: ?PdfFont, boldFont: ?PdfFont}> */
    private array $emptyFields = [];

    /** @var array{appearance: SignatureAppearance, font: ?PdfFont, boldFont: ?PdfFont}|null */
    private ?array $stamp = null;

    /** @var array<int, list<PdfReference>> page object → widgets added in this update */
    private array $pageWidgets = [];

    private function __construct(private readonly string $pdf) {}

    public static function fromBytes(string $pdf): self
    {
        return new self($pdf);
    }

    /**
     * Signature fields already in the document.
     *
     * @return list<SignatureFieldInfo>
     */
    public function signatureFields(): array
    {
        $doc = ReaderDocument::fromBytes($this->pdf);
        $pageOf = $this->widgetPages($doc);
        $out = [];
        foreach ((new FieldTree)->build($doc) as $name => $node) {
            if ($node->type !== 'signature') {
                continue;
            }
            $field = $doc->getObject($node->fieldObjNum);
            $widgetNum = $node->widgetObjNums[0] ?? $node->fieldObjNum;
            $widget = $doc->getObject($widgetNum);
            $rect = $widget instanceof PdfDictionary ? $doc->deref($widget->get('Rect')) : null;
            $out[] = new SignatureFieldInfo(
                $name,
                $pageOf[$widgetNum] ?? null,
                is_array($rect) && count($rect) === 4 ? array_map(static fn ($v): float => (float) $v, array_values($rect)) : null,
                $field instanceof PdfDictionary && $field->get('V') !== null,
            );
        }

        return $out;
    }

    /**
     * Adds a visible signature field to sign into.
     *
     * @param  int  $page  Zero-based page index.
     * @param  ?PdfFont  $font  Font for stamp text when the appearance sets none.
     */
    public function addField(
        string $name,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        ?SignatureAppearance $appearance = null,
        ?PdfFont $font = null,
        ?PdfFont $boldFont = null,
    ): self {
        if ($name === '' || str_contains($name, '.')) {
            throw new \InvalidArgumentException('A new signature field needs a name without dots');
        }
        $this->newField = [
            'name' => $name,
            'page' => $page,
            'rect' => [$x, $y, $x + $width, $y + $height],
            'appearance' => $appearance,
            'font' => $font,
            'boldFont' => $boldFont,
        ];

        return $this;
    }

    /**
     * The stamp drawn into the field being signed when it already exists in
     * the document — its appearance is redrawn with the certificate and
     * signature details. (A field added with {@see addField()} takes its own
     * appearance, else this one.)
     */
    public function withAppearance(SignatureAppearance $appearance, ?PdfFont $font = null, ?PdfFont $boldFont = null): self
    {
        $this->stamp = ['appearance' => $appearance, 'font' => $font, 'boldFont' => $boldFont];

        return $this;
    }

    /**
     * Adds an empty signature field for a later signer, in the same update.
     * Its stamp is drawn with the appearance's own variables only.
     */
    public function addEmptyField(
        string $name,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        ?SignatureAppearance $appearance = null,
        ?PdfFont $font = null,
        ?PdfFont $boldFont = null,
    ): self {
        if ($name === '' || str_contains($name, '.')) {
            throw new \InvalidArgumentException('A new signature field needs a name without dots');
        }
        $this->emptyFields[] = [
            'name' => $name,
            'page' => $page,
            'rect' => [$x, $y, $x + $width, $y + $height],
            'appearance' => $appearance,
            'font' => $font,
            'boldFont' => $boldFont,
        ];

        return $this;
    }

    /** Signs and returns the PDF with the signature appended. */
    public function sign(SignatureConfig $config): string
    {
        $config = $this->withFixedTime($config);
        [$bytes, $placeholder] = $this->build($config);

        return $placeholder->embed($bytes, $config->signBytes($placeholder->signedContent($bytes)));
    }

    /**
     * Prepares the signature for a key outside the process; see
     * {@see PreparedSignature}.
     */
    public function prepare(SignatureConfig $config, ExternalMode $mode = ExternalMode::Cms): PreparedSignature
    {
        $config = $this->withFixedTime($config);
        [$bytes, $placeholder] = $this->build($config);

        return PreparedSignature::create(
            $bytes,
            $placeholder,
            $mode,
            $config->signer->digestAlgorithm(),
            $config->certificate(),
            $config->signer->chain(),
            $config->subFilter === SubFilter::Pkcs7Detached ? $config->effectiveSignedAt() : null,
        );
    }

    /**
     * @return array{0: string, 1: SignaturePlaceholder}
     */
    private function build(SignatureConfig $config): array
    {
        $this->pageWidgets = [];
        $update = IncrementalUpdate::fromBytes($this->pdf);
        $doc = $update->document();
        $catalog = $doc->catalog();
        $fields = (new FieldTree)->build($doc);

        $certified = $this->checkPermissions($doc, $fields, $config);
        $names = array_keys($fields);
        foreach ([...($this->newField !== null ? [$this->newField] : []), ...$this->emptyFields] as $spec) {
            if (in_array($spec['name'], $names, true)) {
                throw new \InvalidArgumentException(sprintf('The document already has a field named "%s"', $spec['name']));
            }
            $names[] = $spec['name'];
        }

        $sigNum = $update->add(SignatureDictionary::body($config));
        $sigRef = new PdfReference($sigNum, 0);
        $lock = $config->lockFields;

        // Which field: an existing empty one, or a new one.
        $target = $this->targetField($doc, $fields, $config);

        if ($certified && ($target === null || $this->emptyFields !== [])) {
            throw new \LogicException(
                'The document is certified: it can only be signed in an empty signature field it already has; '
                .'new fields must be added in the certifying update (addEmptyField())',
            );
        }

        $newFieldRefs = [];
        foreach ($this->emptyFields as $spec) {
            $newFieldRefs[] = $this->addWidget($update, $doc, $spec, null, $config);
        }
        if ($target === null) {
            $spec = $this->newField ?? [
                'name' => $this->freeName($fields),
                'page' => 0,
                'rect' => [0.0, 0.0, 0.0, 0.0],
                'appearance' => null,
                'font' => null,
                'boldFont' => null,
            ];
            if ($spec['appearance'] === null && $this->stamp !== null) {
                $spec = [...$spec, ...$this->stamp];
            }
            $newFieldRefs[] = $this->addWidget($update, $doc, $spec, $sigRef, $config);
        } else {
            $fieldDict = $doc->getObject($target);
            if (! $fieldDict instanceof PdfDictionary) {
                throw new \LogicException('The signature field is not a dictionary');
            }
            $items = [...$fieldDict->all(), 'V' => $sigRef];
            if ($lock !== null) {
                $items['Lock'] = new RawPdf(SignatureDictionary::lock($lock));
            }
            // A stamp for the existing field: its widget's appearance redrawn.
            $node = null;
            foreach ($fields as $candidate) {
                if ($candidate->fieldObjNum === $target) {
                    $node = $candidate;
                }
            }
            $widgetNum = $node->widgetObjNums[0] ?? $target;
            if ($this->stamp !== null) {
                $widget = $widgetNum === $target ? $fieldDict : $doc->getObject($widgetNum);
                $rect = $widget instanceof PdfDictionary ? $doc->deref($widget->get('Rect')) : null;
                if (is_array($rect) && count($rect) === 4) {
                    [$x1, $y1, $x2, $y2] = array_map(static fn ($v): float => (float) $v, array_values($rect));
                    $w = abs($x2 - $x1);
                    $h = abs($y2 - $y1);
                    if ($w > 0.0 && $h > 0.0) {
                        $ap = $this->appearanceStream($update, [...$this->stamp, 'name' => '', 'page' => 0, 'rect' => [0.0, 0.0, $w, $h]], $config, $w, $h);
                        $apValue = new RawPdf(sprintf('<< /N %d 0 R >>', $ap));
                        if ($widgetNum === $target) {
                            $items['AP'] = $apValue;
                        } elseif ($widget instanceof PdfDictionary) {
                            $update->replaceBody($widgetNum, self::encode([...$widget->all(), 'AP' => $apValue]));
                        }
                    }
                }
            }
            $update->replaceBody($target, self::encode($items));
        }

        $this->registerFields($update, $newFieldRefs, $config->certification !== null ? ['Perms' => new PdfDictionary(['DocMDP' => $sigRef])] : []);

        $original = strlen($this->pdf);
        $bytes = $update->toBytes();

        return SignaturePlaceholder::locate($bytes, $original);
    }

    /**
     * Lists new fields in /AcroForm, sets SigFlags 3 (SignaturesExist |
     * AppendOnly), and applies catalog changes.
     *
     * @param  list<PdfReference>  $newFieldRefs
     * @param  array<string, mixed>  $catalogChanges
     */
    private function registerFields(IncrementalUpdate $update, array $newFieldRefs, array $catalogChanges = []): void
    {
        $doc = $update->document();
        $catalog = $doc->catalog();
        $acroForm = $catalog->get('AcroForm');
        $acroDict = $doc->deref($acroForm);
        $acroItems = $acroDict instanceof PdfDictionary ? $acroDict->all() : [];
        if ($newFieldRefs !== []) {
            $existing = $doc->deref($acroItems['Fields'] ?? null);
            $list = [...(is_array($existing) ? $existing : []), ...$newFieldRefs];
            if (($acroItems['Fields'] ?? null) instanceof PdfReference) {
                $update->replace($acroItems['Fields']->number, $list);
            } else {
                $acroItems['Fields'] = $list;
            }
        }
        $acroItems['SigFlags'] = 3;

        $catalogItems = [...$catalog->all(), ...$catalogChanges];
        if ($acroForm instanceof PdfReference) {
            $update->replace($acroForm->number, new PdfDictionary($acroItems));
        } else {
            $catalogItems['AcroForm'] = new PdfReference($update->add(self::encode($acroItems)), 0);
        }
        if ($catalogChanges !== [] || ! $acroForm instanceof PdfReference) {
            $update->replace($update->rootReference()->number, new PdfDictionary($catalogItems));
        }
    }

    /**
     * Adds the document security store (DSS) — certificates, OCSP responses
     * and CRLs for every signature and timestamp in the document — as an
     * incremental update: PAdES B-LT. Earlier signatures stay valid.
     *
     * @param  ?RevocationSource  $revocation  Where OCSP / CRL come from; by default the network. Null data source and
     *                                         `$fetchRevocation = false` embed only the certificates.
     * @param  ?ValidationData  $extra  Data to embed as well (from your own store).
     */
    public function addValidationData(
        ?RevocationSource $revocation = null,
        ?ValidationData $extra = null,
        bool $fetchRevocation = true,
        ?CertificateFetcher $issuers = null,
    ): string {
        $update = IncrementalUpdate::fromBytes($this->pdf);
        $doc = $update->document();

        $certificates = $this->embeddedCertificates($doc);
        if ($certificates === [] && $extra === null) {
            throw new \LogicException('The document has no signatures to add validation data for');
        }
        $data = new ValidationData($certificates);
        if ($fetchRevocation) {
            $revocation ??= new OnlineRevocationSource;
            $issuers ??= new CertificateFetcher;
            // Walk up every chain: issuers come from the document, from
            // `$extra`, or are downloaded (the root a chain leaves out).
            $pool = [...$certificates, ...($extra->certificates ?? [])];
            $queue = $certificates;
            $done = [];
            while ($queue !== []) {
                $cert = array_shift($queue);
                if ($cert->isSelfIssued() || isset($done[$cert->der])) {
                    continue;
                }
                $done[$cert->der] = true;
                $issuer = null;
                foreach ($pool as $candidate) {
                    if ($candidate->subjectDer() === $cert->issuerDer()) {
                        $issuer = $candidate;
                    }
                }
                $issuer ??= $issuers->issuerOf($cert);
                if ($issuer === null) {
                    throw new LtvException(sprintf('The issuer of "%s" is not known; pass it in $extra', $cert->subjectCommonName()));
                }
                $pool[] = $issuer;
                $data = $data->merge(new ValidationData([$issuer]))->merge($revocation->revocationData($cert, $issuer));
                $queue[] = $issuer;
            }
        }
        if ($extra !== null) {
            $data = $data->merge($extra);
        }

        // Merge with a DSS already there.
        $catalog = $doc->catalog();
        $dss = $doc->deref($catalog->get('DSS'));
        $items = $dss instanceof PdfDictionary ? $dss->all() : [];
        $append = function (string $key, array $blobs) use (&$items, $doc, $update): void {
            $existing = $doc->deref($items[$key] ?? null);
            $refs = is_array($existing) ? $existing : [];
            foreach ($blobs as $blob) {
                $compressed = (string) gzcompress($blob, 6);
                $refs[] = new PdfReference($update->add(sprintf(
                    "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
                    strlen($compressed), $compressed,
                )), 0);
            }
            if ($refs !== []) {
                $items[$key] = $refs;
            }
        };
        $append('Certs', array_map(static fn (Certificate $c): string => $c->der, $data->certificates));
        $append('OCSPs', $data->ocspResponses);
        $append('CRLs', $data->crls);
        $items['Type'] = new PdfName('DSS');

        $dssRef = new PdfReference($update->add(self::encode($items)), 0);
        $update->replace($update->rootReference()->number, new PdfDictionary([...$catalog->all(), 'DSS' => $dssRef]));

        return $update->toBytes();
    }

    /**
     * Adds a document timestamp (`ETSI.RFC3161`) over the whole file as an
     * incremental update — after {@see addValidationData()}, PAdES B-LTA;
     * repeat later to extend the archive before the timestamp ages out.
     */
    public function timestamp(TsaClient $tsa, int $contentsReserveBytes = 16384): string
    {
        $this->pageWidgets = [];
        $update = IncrementalUpdate::fromBytes($this->pdf);
        $doc = $update->document();
        $fields = (new FieldTree)->build($doc);

        $sigNum = $update->add(sprintf(
            '<< /Type /DocTimeStamp /Filter /Adobe.PPKLite /SubFilter /ETSI.RFC3161 /ByteRange [0          0          0          0         ] /Contents <%s> >>',
            str_repeat('0', 2 * $contentsReserveBytes),
        ));
        $name = 'DocTimeStamp';
        for ($i = 1; isset($fields[$name]); $i++) {
            $name = 'DocTimeStamp'.$i;
        }
        $widget = $this->addWidget($update, $doc, [
            'name' => $name, 'page' => 0, 'rect' => [0.0, 0.0, 0.0, 0.0], 'appearance' => null, 'font' => null, 'boldFont' => null,
        ], new PdfReference($sigNum, 0), null);
        $this->registerFields($update, [$widget]);

        [$bytes, $placeholder] = SignaturePlaceholder::locate($update->toBytes(), strlen($this->pdf));

        return $placeholder->embed($bytes, $tsa->timestamp($placeholder->signedContent($bytes))->token);
    }

    /**
     * Every certificate carried by the document's signatures and their
     * timestamp tokens, and by its document timestamps.
     *
     * @return list<Certificate>
     */
    private function embeddedCertificates(ReaderDocument $doc): array
    {
        $out = [];
        $add = static function (Certificate $cert) use (&$out): void {
            foreach ($out as $known) {
                if ($known->der === $cert->der) {
                    return;
                }
            }
            $out[] = $cert;
        };
        foreach ((new FieldTree)->build($doc) as $node) {
            if ($node->type !== 'signature') {
                continue;
            }
            $field = $doc->getObject($node->fieldObjNum);
            $sig = $field instanceof PdfDictionary ? $doc->deref($field->get('V')) : null;
            $contents = $sig instanceof PdfDictionary ? $doc->deref($sig->get('Contents')) : null;
            if (! $contents instanceof PdfString) {
                continue;
            }
            try {
                $cms = SignedDataReader::parse($contents->bytes);
            } catch (\Throwable) {
                continue;
            }
            array_map($add, $cms->certificates());
            foreach ($cms->unsignedAttributeValues(Oid::SIGNATURE_TIMESTAMP_TOKEN) as $token) {
                try {
                    array_map($add, SignedDataReader::parse($token)->certificates());
                } catch (\Throwable) {
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, \Dskripchenko\PhpPdf\Pdf\Forms\FieldNode>  $fields
     */
    private function targetField(ReaderDocument $doc, array $fields, SignatureConfig $config): ?int
    {
        $wanted = $config->fieldName;
        if ($wanted !== null && $this->newField !== null && $this->newField['name'] === $wanted) {
            return null;
        }
        if ($wanted !== null) {
            $node = $fields[$wanted] ?? throw new \InvalidArgumentException(sprintf('No signature field named "%s"', $wanted));
            if ($node->type !== 'signature') {
                throw new \InvalidArgumentException(sprintf('Field "%s" is not a signature field', $wanted));
            }
            if ($this->isSigned($doc, $node->fieldObjNum)) {
                throw new \InvalidArgumentException(sprintf('Field "%s" is already signed', $wanted));
            }

            return $node->fieldObjNum;
        }
        if ($this->newField !== null) {
            return null;
        }
        foreach ($fields as $node) {
            if ($node->type === 'signature' && ! $this->isSigned($doc, $node->fieldObjNum)) {
                return $node->fieldObjNum;
            }
        }

        return null;
    }

    /**
     * A new field-and-widget on a page; returns its reference.
     *
     * @param  array{name: string, page: int, rect: array{float, float, float, float}, appearance: ?SignatureAppearance, font: ?PdfFont, boldFont: ?PdfFont}  $spec
     */
    private function addWidget(IncrementalUpdate $update, ReaderDocument $doc, array $spec, ?PdfReference $sigRef, ?SignatureConfig $config): PdfReference
    {
        $pages = $doc->pages();
        $page = $pages[$spec['page']] ?? throw new \InvalidArgumentException(sprintf('The document has no page %d', $spec['page']));
        if ($page->objectNumber < 1) {
            throw new \InvalidArgumentException('The page is not an indirect object');
        }
        [$x1, $y1, $x2, $y2] = $spec['rect'];
        $w = $x2 - $x1;
        $h = $y2 - $y1;

        $widgetNum = $update->allocate();
        $parts = [
            '/Type /Annot /Subtype /Widget /FT /Sig',
            '/T '.SignatureDictionary::textString($spec['name']),
            ...($sigRef !== null ? [sprintf('/V %d 0 R', $sigRef->number)] : []),
            '/F 4',
            sprintf('/Rect [%s %s %s %s]', ...array_map([AppearanceRenderer::class, 'num'], $spec['rect'])),
            sprintf('/P %d %d R', $page->objectNumber, $doc->generationOf($page->objectNumber)),
        ];
        if ($sigRef !== null && $config?->lockFields !== null) {
            $parts[] = '/Lock '.SignatureDictionary::lock($config->lockFields);
        }
        if ($w > 0.0 && $h > 0.0) {
            $parts[] = sprintf('/AP << /N %d 0 R >>', $this->appearanceStream($update, $spec, $sigRef !== null ? $config : null, $w, $h));
        }
        $update->writer()->setObject($widgetNum, '<< '.implode(' ', $parts).' >>');
        $widgetRef = new PdfReference($widgetNum, 0);

        // The page lists the widget among its annotations (several new
        // widgets on one page accumulate).
        $this->pageWidgets[$page->objectNumber][] = $widgetRef;
        $pageItems = $page->dict->all();
        $annots = $pageItems['Annots'] ?? null;
        if ($annots instanceof PdfReference) {
            $list = $doc->deref($annots);
            $update->replace($annots->number, [...(is_array($list) ? $list : []), ...$this->pageWidgets[$page->objectNumber]]);
        } else {
            $pageItems['Annots'] = [...(is_array($annots) ? $annots : []), ...$this->pageWidgets[$page->objectNumber]];
            $update->replace($page->objectNumber, new PdfDictionary($pageItems));
        }

        return $widgetRef;
    }

    /**
     * @param  array{name: string, page: int, rect: array{float, float, float, float}, appearance: ?SignatureAppearance, font: ?PdfFont, boldFont: ?PdfFont}  $spec
     */
    private function appearanceStream(IncrementalUpdate $update, array $spec, ?SignatureConfig $config, float $w, float $h): int
    {
        $resources = new UpdateResources;
        $appearance = $spec['appearance'];
        $content = $appearance === null
            ? sprintf("q\n0.5 w\n0 0 %s %s re S\nQ\n", AppearanceRenderer::num($w), AppearanceRenderer::num($h))
            : (new AppearanceRenderer($resources, $spec['font'], $spec['boldFont']))
                ->render($appearance, $config !== null ? StampValues::of($config, $appearance) : [], $w, $h);
        $resourceDict = $resources->emit($update);
        $compressed = (string) gzcompress($content, 6);

        return $update->add(sprintf(
            "<< /Type /XObject /Subtype /Form /BBox [0 0 %s %s] /Resources %s /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
            AppearanceRenderer::num($w), AppearanceRenderer::num($h), $resourceDict, strlen($compressed), $compressed,
        ));
    }

    /**
     * Whether the document carries a certification signature.
     *
     * @param  array<string, \Dskripchenko\PhpPdf\Pdf\Forms\FieldNode>  $fields
     */
    private function checkPermissions(ReaderDocument $doc, array $fields, SignatureConfig $config): bool
    {
        $signed = false;
        foreach ($fields as $node) {
            if ($node->type === 'signature' && $this->isSigned($doc, $node->fieldObjNum)) {
                $signed = true;

                break;
            }
        }
        if ($config->certification !== null && $signed) {
            throw new \LogicException('Only the first signature can certify a document; this one is already signed');
        }

        $perms = $doc->deref($doc->catalog()->get('Perms'));
        $docMdp = $perms instanceof PdfDictionary ? $doc->deref($perms->get('DocMDP')) : null;
        if ($docMdp instanceof PdfDictionary && self::docMdpLevel($doc, $docMdp) === DocMdpPermission::NoChanges->value) {
            throw new \LogicException('The document is certified with no changes allowed; it cannot be signed again');
        }

        return $docMdp instanceof PdfDictionary;
    }

    /** /P of a certification signature's DocMDP transform; 2 when absent (§12.8.2.2). */
    public static function docMdpLevel(ReaderDocument $doc, PdfDictionary $sigDict): int
    {
        $refs = $doc->deref($sigDict->get('Reference'));
        foreach (is_array($refs) ? $refs : [] as $ref) {
            $ref = $doc->deref($ref);
            if (! $ref instanceof PdfDictionary) {
                continue;
            }
            $method = $ref->get('TransformMethod');
            if ($method instanceof PdfName && $method->value === 'DocMDP') {
                $params = $doc->deref($ref->get('TransformParams'));
                $p = $params instanceof PdfDictionary ? $doc->deref($params->get('P')) : null;

                return is_int($p) ? $p : 2;
            }
        }

        return 2;
    }

    private function isSigned(ReaderDocument $doc, int $fieldNum): bool
    {
        $field = $doc->getObject($fieldNum);

        return $field instanceof PdfDictionary && $doc->deref($field->get('V')) instanceof PdfDictionary;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function freeName(array $fields): string
    {
        for ($i = 1; ; $i++) {
            if (! isset($fields["Signature$i"])) {
                return "Signature$i";
            }
        }
    }

    /**
     * Widget object number → zero-based page index.
     *
     * @return array<int, int>
     */
    private function widgetPages(ReaderDocument $doc): array
    {
        $out = [];
        foreach ($doc->pages() as $index => $page) {
            $annots = $doc->deref($page->dict->get('Annots'));
            foreach (is_array($annots) ? $annots : [] as $ref) {
                if ($ref instanceof PdfReference) {
                    $out[$ref->number] = $index;
                }
            }
        }

        return $out;
    }

    private function withFixedTime(SignatureConfig $config): SignatureConfig
    {
        return $config->signedAt === null ? $config->withSignedAt(new \DateTimeImmutable) : $config;
    }

    /**
     * A dictionary body from parsed values, some of them raw PDF syntax.
     *
     * @param  array<string, mixed>  $items
     */
    private static function encode(array $items): string
    {
        $out = '<<';
        foreach ($items as $key => $value) {
            $out .= '/'.$key.' '.($value instanceof RawPdf ? $value->syntax : IncrementalUpdate::encode($value));
        }

        return $out.'>>';
    }
}
