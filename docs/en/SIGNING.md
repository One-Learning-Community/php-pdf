# Digital signatures

php-pdf signs a document as it writes it: PKCS#7 detached signature
(`adbe.pkcs7.detached`) through the `openssl` extension, with the
`/ByteRange` and `/Contents` placeholders patched after the file is
assembled. The signature field can show a **signature stamp**: a frame
with text, pictures and a QR code, filled in from the certificate when
the document is signed.

- [Signing](#signing)
- [Signature fields](#signature-fields)
- [The stamp](#the-stamp)
- [Ready-made stamps](#ready-made-stamps)
- [Placeholders](#placeholders)
- [Fonts](#fonts)
- [Several signature fields](#several-signature-fields)
- [Stamp on every page](#stamp-on-every-page)
- [PDF/A](#pdfa)

## Signing

```php
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;

$doc = Document::new();
$page = $doc->addPage();
$page->addSignatureField('approval', x: 72, y: 600, width: 250, height: 75,
    appearance: SignatureAppearance::simple());

$doc->sign(new SignatureConfig(
    certPem: file_get_contents('signer.crt'),
    privateKeyPem: file_get_contents('signer.key'),
    privateKeyPassphrase: 'optional',
    signerName: 'Jane Doe',
    reason: 'Document approval',
    location: 'Berlin',
    contactInfo: 'jane@example.com',
));
$doc->toFile('signed.pdf');
```

`signedAt` sets the signing time; by default it is the time of writing.
The same moment goes into the signature dictionary (`/M`) and into the
stamp.

## Signature fields

In a laid-out document a signature field is a block in the flow, like an
image:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;

$doc = new Document(new Section([
    // … the document body …
    new SignatureField('approval',
        widthPt: 250, heightPt: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('en'),
        alignment: Alignment::End),
]), signature: new SignatureConfig($certPem, $keyPem));
```

On a page drawn by hand it is `Page::addSignatureField()` with absolute
coordinates. A zero-size field (`width: 0, height: 0`) is an
invisible signature. A field without an appearance is a thin empty
frame.

`FormField(type: 'signature')` and `Page::addFormField('signature', …)`
keep working; they draw the empty frame.

## The stamp

`SignatureAppearance` is a frame — border colour and width, rounded
corners, background, padding — holding blocks drawn in order:

| Block | What it draws |
|---|---|
| `TextBlock` | lines with placeholders; wrapped at spaces and shrunk down to `minSizePt` to fit, clipped beyond that; alignment, vertical alignment, colour, bold |
| `ImageBlock` | PNG or JPEG — a scanned signature, a seal, an emblem; fitted keeping proportions; PNG transparency and `opacity` |
| `QrBlock` | a QR code, e.g. a link to a verification page; placeholders allowed in the data |

A block's place is a `Box` — fractions of the inner area, origin at the
top-left corner:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\{Box, ImageBlock, QrBlock, SignatureAppearance, TextBlock};
use Dskripchenko\PhpPdf\Style\VerticalAlignment;

$stamp = new SignatureAppearance(
    [
        new ImageBlock(PdfImage::fromPath('seal.png'), new Box(0, 0, 0.3, 1), opacity: 0.8),
        new TextBlock(
            ['Signed by {signer}', '{org}', 'Certificate {serial}', '{signedAt}'],
            new Box(0.32, 0, 0.48, 1),
            sizePt: 8,
            verticalAlign: VerticalAlignment::Center,
        ),
        new QrBlock('https://example.com/verify?serial={serial}', new Box(0.8, 0, 0.2, 1)),
    ],
    borderColor: '#1F3A93',
    borderWidthPt: 1.5,
    cornerRadiusPt: 6,
    font: $font,
    dateTimeFormat: 'Y-m-d H:i',
);
```

`withFonts()`, `withVariables()` and `withBlocks()` return a changed copy.

## Ready-made stamps

| Preset | Look |
|---|---|
| `SignatureAppearance::electronicSignatureStamp('ru' \| 'en', $font, $boldFont, $color, $emblem)` | the customary electronic-signature mark: coloured rounded frame, "DOCUMENT SIGNED WITH AN ELECTRONIC SIGNATURE", then certificate serial, owner and validity period; optional emblem on the left; about 250 × 75 pt |
| `SignatureAppearance::simple($font, $color)` | "Digitally signed by …", the date, the reason and location when set |
| `SignatureAppearance::imageOnly($image, $borderColor)` | a picture only |

## Placeholders

| Placeholder | Value |
|---|---|
| `{cn}`, `{org}`, `{email}` | the certificate subject's CN, O, e-mail |
| `{serial}` | certificate serial number, upper-case hex |
| `{validFrom}`, `{validTo}` | certificate validity, in `dateFormat` |
| `{issuer}` | the issuer's CN |
| `{inn}`, `{innle}`, `{ogrn}`, `{ogrnip}`, `{snils}` | identifiers of Russian qualified certificates |
| `{signer}` | `SignatureConfig::$signerName`, else the certificate's CN |
| `{signedAt}` | signing time, in `dateTimeFormat` |
| `{reason}`, `{location}`, `{contact}` | from `SignatureConfig` |
| any key of `variables` | your own values |

A line whose placeholders all come out empty is dropped, so
`'INN: {inn}'` disappears for a certificate without one.

## Fonts

Stamp text needs a font with the glyphs it uses:

1. `SignatureAppearance::$font` / `$boldFont`, if set;
2. inside a laid-out document — the document's default font and bold
   font (`Engine` `defaultFont` / `boldFont`);
3. otherwise Helvetica, which covers only WinAnsi (Latin). Text outside it
   is refused with an exception rather than drawn as garbage.

Without a bold font, bold text is emboldened by stroking the glyph
outlines.

## Several signature fields

Name the field to sign with `SignatureConfig::$fieldName`; without it the
first signature field is signed. The other fields stay empty for later
signers: their stamps are drawn with their own `variables` only, so they
can say "Place for signature". An unknown field name is an error.

## Stamp on every page

`repeatOnOtherPages: true` also draws the stamp at the same position on
every other page, as plain content marked as an artifact. Only the
original field is interactive — viewers and validators treat several
widgets of one signature field differently, so the copies are not
widgets.

## PDF/A

A signed PDF/A-2b document with a stamp validates with veraPDF on every
push. PDF/A needs every font embedded, so a stamp under PDF/A must have
an embedded font (from the appearance or the document); Helvetica is
refused there. `NeedAppearances` is left out of signed and PDF/A
documents.
