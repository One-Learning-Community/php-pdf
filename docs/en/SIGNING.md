# Digital signatures

php-pdf signs a document as it writes it: a detached CMS signature in
the **PAdES** format (`ETSI.CAdES.detached`, ETSI EN 319 142), built by
the library itself and signed through the `openssl` extension, with the
`/ByteRange` and `/Contents` placeholders patched after the file is
assembled. RSA, ECDSA and GOST R 34.10-2012 keys are supported; with a
timestamp authority the signature reaches PAdES B-T. The signature field
can show a **signature stamp**: a frame with text, pictures and a QR
code, filled in from the certificate when the document is signed.

- [Signing](#signing)
- [Keys and certificates](#keys-and-certificates)
- [Signature format](#signature-format)
- [Timestamp](#timestamp)
- [GOST R 34.10-2012](#gost-r-3410-2012)
- [Signature size](#signature-size)
- [Signing outside the server](#signing-outside-the-server)
- [Signing an existing PDF](#signing-an-existing-pdf)
- [Certification and locked fields](#certification-and-locked-fields)
- [Long-term validation](#long-term-validation)
- [Checking a signature](#checking-a-signature)
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

## Keys and certificates

Three ways to hand over the key:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;

// 1. PEM strings, with the intermediate certificates so a validator can
//    build the path to a trusted root.
new SignatureConfig(
    certPem: $cert, privateKeyPem: $key, privateKeyPassphrase: 'secret',
    certificateChainPem: file_get_contents('intermediate.crt'),
);

// 2. A PKCS#12 container (.p12 / .pfx) — key, certificate and chain.
new SignatureConfig(signer: OpenSslSigner::fromPkcs12(file_get_contents('signer.p12'), 'password'));

// 3. Your own Signer — a PKCS#11 token, an HSM, a signing service.
new SignatureConfig(signer: $mySigner);
```

`Signer` is a small interface: `certificate()`, `chain()`,
`digestAlgorithm()` and `sign(string $data): string`. The library hands
`sign()` the DER of the signed attributes and expects the raw signature
value back, so the key never has to be in PHP.

The digest follows the key: SHA-256 for RSA and P-256, SHA-384 for P-384,
SHA-512 for P-521, Streebog for GOST. Pass `digest:` to `OpenSslSigner`
to choose another.

## Signature format

| `subFilter` | What it is |
|---|---|
| `SubFilter::CadesDetached` (default) | PAdES: CAdES detached CMS with `signing-certificate-v2` binding the certificate; the signing time is `/M`, not a CMS attribute. PAdES B-B, B-T with a timestamp |
| `SubFilter::Pkcs7Detached` | the older `adbe.pkcs7.detached`; the CMS carries `signing-time`, set to the same moment as `/M` |

Both are detached CMS `SignedData` with the signer's certificate and the
chain embedded. Keep the default unless a recipient's software predates
PAdES.

## Timestamp

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

new SignatureConfig(
    signer: $signer,
    timestampAuthority: new TsaClient('https://freetsa.org/tsr'),
);
```

The signature value is sent to the RFC 3161 timestamp authority and the
token goes into the signature as the `signature-time-stamp` attribute:
the signing time is then vouched for by the TSA, not by the signer's
clock (PAdES B-T). `TsaClient` takes the digest, HTTP basic credentials,
extra headers (an API key), a policy OID and a timeout. It checks the
answer — granted status, the imprint and the nonce it asked for — and
throws `TsaException` otherwise.

Requests go through PHP's stream wrapper with TLS verification. To use
your HTTP client, proxy or retry policy, implement
`Transport\HttpTransport` and pass it as `transport:`.

## GOST R 34.10-2012

GOST keys (2012, 256 and 512 bit) sign the same way as RSA, with the
Streebog digest and the identifiers OpenSSL and CryptoPro use. PHP must
have OpenSSL's [gost engine](https://github.com/gost-engine/engine)
loaded — it is not packaged for OpenSSL 3, so it is built from source and
switched on in `openssl.cnf`. `docker/gost/` in this repository is a
working recipe (the CI runs the GOST tests in it). Without the engine a
GOST key cannot be read, and the error says so.

## Signature size

Room for the signature is reserved in `/Contents` before it exists. The
library estimates it from the certificates, the key size and whether a
timestamp is asked for. If a signer embeds more than expected, signing
fails with a message giving the size needed; set `contentsReserveBytes`
to override the estimate.

## Signing outside the server

When the key cannot come to the server — a qualified certificate on a
token or in CryptoPro on the user's machine, an HSM that signs on request,
a cloud signing service — sign in two steps:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\External\{ExternalMode, ExternalSigner, PreparedSignature};

// 1. Emit the document with an empty signature.
$prepared = $doc->prepareSignature(new SignatureConfig(
    // The certificate, when known, goes on the stamp; it may be left out.
    signer: new ExternalSigner(certificatePem: $userCertificatePem),
    reason: 'Approval',
));
$state = $prepared->toJson();        // keep it — session, cache, database
$hash = $prepared->documentDigestHex(); // send to the signer

// 2. Later, with what the signer returned (base64 CMS here).
$pdf = PreparedSignature::fromJson($state)->completeBase64($cmsBase64);
```

| Mode | The signer gets | It returns |
|---|---|---|
| `ExternalMode::Cms` (default) | `documentDigest()` — or `signedContent()`, the bytes themselves, for a tool that signs files | a detached CMS (CAdES-BES) |
| `ExternalMode::RawSignature` | `dataToSign()` — the signed attributes the library built — or `hashToSign()` for a device that signs a hash | the raw signature value |

`complete()` checks what came back before embedding it: the CMS covers
exactly this document (its message digest), it was made with the
certificate given to `prepareSignature()` (if one was), its signature
verifies, and it fits the reserved space. Certificates the CMS lacks are
added from the preparation; a timestamp authority passed to `complete()`
timestamps the signature. A GOST raw value in CryptoAPI's little-endian
order is recognised and turned around. CMS written as BER (CryptoPro
uses indefinite lengths) is accepted, and an attached signature — the
default of many tools — is made detached when the content inside is this
document.

Keep the state where the signer cannot change it: it carries the prepared
document, and its checksum only catches damage, not tampering. Once
prepared, the document must not be regenerated — the signature covers
those exact bytes.

### CryptoPro Browser plug-in

In the browser, the plug-in signs the hash with the user's certificate and
returns a CMS — a sketch on top of CryptoPro's `cadesplugin` API:

```js
// hashHex — documentDigestHex() from the server; cert — the user's
// certificate, picked from the store (CAdESCOM.Store).
const hashed = await cadesplugin.CreateObjectAsync("CAdESCOM.HashedData");
await hashed.propset_Algorithm(cadesplugin.CADESCOM_HASH_ALGORITHM_CP_GOST_3411_2012_256);
await hashed.SetHashValue(hashHex);

const signer = await cadesplugin.CreateObjectAsync("CAdESCOM.CPSigner");
await signer.propset_Certificate(cert);
await signer.propset_Options(cadesplugin.CAPICOM_CERTIFICATE_INCLUDE_WHOLE_CHAIN);

const signedData = await cadesplugin.CreateObjectAsync("CAdESCOM.CadesSignedData");
const cmsBase64 = await signedData.SignHash(hashed, signer, cadesplugin.CADESCOM_CADES_BES);
// POST cmsBase64 back → completeBase64()
```

Use the hash algorithm that matches the key: `..._GOST_3411_2012_256` for a
256-bit GOST key, `..._GOST_3411_2012_512` for 512. Pass the certificate to
`ExternalSigner` (export it from the plug-in with
`cert.Export(CADESCOM_ENCODE_BASE64)`) so the digest follows the key and
the stamp shows the owner; computing the Streebog digest needs the gost
engine on the server too. The CMS made this way carries a
`signing-time` attribute; validators accept it, though strict PAdES
profiles expect the time in `/M` only.

## Signing an existing PDF

`PdfSigner` signs a PDF made elsewhere — or one already signed — as an
**incremental update**: the original bytes stay as they are and the
signature is written after them, so earlier signatures keep covering exactly
what they signed. Classic and stream cross-references are both followed.

```php
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;

$signed = PdfSigner::fromBytes(file_get_contents('contract.pdf'))
    ->addField('approver', page: 0, x: 72, y: 72, width: 250, height: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('ru', $font))
    ->sign(new SignatureConfig(signer: $signer));

// A second signer, into an empty field already there; its stamp is redrawn.
$twice = PdfSigner::fromBytes($signed)
    ->withAppearance(SignatureAppearance::simple($font))
    ->sign(new SignatureConfig(signer: $other, fieldName: 'counterparty'));
```

The field signed is `fieldName` if given (an empty signature field of the
document, or the one added with `addField()`), else the field added with
`addField()`, else the first empty signature field, else a new invisible
field on the first page. `signatureFields()` lists the document's signature
fields and whether they are signed. `prepare()` instead of `sign()` gives a
`PreparedSignature` for a key outside the server, as above. Encrypted PDFs
are refused.

## Certification and locked fields

```php
use Dskripchenko\PhpPdf\Pdf\Signature\{DocMdpPermission, FieldLock};

new SignatureConfig(signer: $signer,
    certification: DocMdpPermission::FormFilling,   // what may change afterwards
    lockFields: FieldLock::only(['amount', 'date']), // fields frozen by this signature
);
```

A **certification signature** (DocMDP) states what may change after it:
`NoChanges`, `FormFilling` (filling forms and signing), or
`FormFillingAndAnnotations`. Only the first signature of a document can
certify it. After certification, further signatures go only into the empty
signature fields the document already has — adding a field changes the page,
which certification forbids — so lay them out in the certifying update with
`PdfSigner::addEmptyField()` (or in the document you generate). A document
certified with `NoChanges` refuses further signatures.

`FieldLock::all()`, `only([...])` or `allExcept([...])` locks form fields
(FieldMDP): changing them afterwards invalidates the signature.

Both work for documents you generate (`Pdf\Document::sign()`) and for
existing ones (`PdfSigner`).

## Long-term validation

A signature can be checked years later only if the validator still finds
the certificates and their revocation status — OCSP responders and CRLs
disappear with the CA. PAdES keeps them in the document:

```php
$bt   = $doc->toBytes();                       // B-T: signed with a timestamp
$blt  = PdfSigner::fromBytes($bt)->addValidationData();   // B-LT: + DSS
$blta = PdfSigner::fromBytes($blt)->timestamp($tsa);      // B-LTA: + document timestamp
```

`addValidationData()` collects every certificate of the document's
signatures and their timestamp tokens, downloads missing issuers (AIA
caIssuers — the root a chain leaves out), asks each certificate's OCSP
responder and falls back to its CRL, and writes the document security store
(DSS) as an incremental update. Pass a `RevocationSource` of your own
(a cache, a store) or `extra:` data to embed; `fetchRevocation: false`
embeds certificates only. Calling it again extends the DSS.

`timestamp()` adds a document timestamp (`ETSI.RFC3161`) over the whole file,
DSS included. Repeat `addValidationData()` + `timestamp()` before the last
timestamp's certificate expires to extend the archive.

## Checking a signature

The test suite checks every signature with tools independent of the
library: `openssl cms -verify` up to a test root CA, `openssl ts -verify`
for timestamps, and [pyHanko](https://github.com/MatthiasValvekens/pyHanko)
for the PDF as a whole (`pyhanko sign validate --trust root.crt file.pdf`),
including several signers and certification. Long-term validation is
checked with pyHanko's PAdES-LT / LTA profiles offline: the validator is
given only the TSA's current status, so the signer's revocation data must
come from the DSS (`scripts/conformance/pades-ltv-check.py`).

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
