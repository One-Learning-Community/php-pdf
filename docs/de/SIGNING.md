# Digitale Signaturen

php-pdf signiert ein Dokument beim Schreiben: eine abgesetzte (detached)
CMS-Signatur im Format **PAdES** (`ETSI.CAdES.detached`, ETSI EN 319 142),
von der Bibliothek selbst aufgebaut und über die Erweiterung `openssl`
signiert, wobei die Platzhalter für `/ByteRange` und `/Contents` nach dem
Zusammenbau der Datei gepatcht werden. Unterstützt werden RSA-, ECDSA- und
GOST-R-34.10-2012-Schlüssel; mit einem Zeitstempeldienst (TSA) erreicht die
Signatur PAdES B-T. Das Signaturfeld kann einen **Signaturstempel**
anzeigen: einen Rahmen mit Text, Bildern und einem QR-Code, der beim
Signieren des Dokuments aus dem Zertifikat befüllt wird.

- [Signieren](#signieren)
- [Schlüssel und Zertifikate](#schlüssel-und-zertifikate)
- [Signaturformat](#signaturformat)
- [Zeitstempel](#zeitstempel)
- [GOST R 34.10-2012](#gost-r-3410-2012)
- [Signaturgröße](#signaturgröße)
- [Eine Signatur prüfen](#eine-signatur-prüfen)
- [Signaturfelder](#signaturfelder)
- [Der Stempel](#der-stempel)
- [Vorgefertigte Stempel](#vorgefertigte-stempel)
- [Platzhalter](#platzhalter)
- [Schriften](#schriften)
- [Mehrere Signaturfelder](#mehrere-signaturfelder)
- [Stempel auf jeder Seite](#stempel-auf-jeder-seite)
- [PDF/A](#pdfa)

## Signieren

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

`signedAt` legt den Signierzeitpunkt fest; standardmäßig ist es der
Zeitpunkt des Schreibens. Derselbe Zeitpunkt landet im Signaturwörterbuch
(`/M`) und im Stempel.

## Schlüssel und Zertifikate

Drei Wege, den Schlüssel zu übergeben:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;

// 1. PEM-Strings, mit den Zwischenzertifikaten, damit ein Validator den Pfad
//    zu einem vertrauenswürdigen Stammzertifikat aufbauen kann.
new SignatureConfig(
    certPem: $cert, privateKeyPem: $key, privateKeyPassphrase: 'secret',
    certificateChainPem: file_get_contents('intermediate.crt'),
);

// 2. Ein PKCS#12-Container (.p12 / .pfx) — Schlüssel, Zertifikat und Kette.
new SignatureConfig(signer: OpenSslSigner::fromPkcs12(file_get_contents('signer.p12'), 'password'));

// 3. Ein eigener Signer — ein PKCS#11-Token, ein HSM, ein Signaturdienst.
new SignatureConfig(signer: $mySigner);
```

`Signer` ist ein kleines Interface: `certificate()`, `chain()`,
`digestAlgorithm()` und `sign(string $data): string`. Die Bibliothek
übergibt `sign()` das DER der signierten Attribute und erwartet den rohen
Signaturwert zurück, sodass der Schlüssel nie in PHP vorliegen muss.

Der Digest richtet sich nach dem Schlüssel: SHA-256 für RSA und P-256,
SHA-384 für P-384, SHA-512 für P-521, Streebog für GOST. Mit `digest:` an
`OpenSslSigner` lässt sich ein anderer wählen.

## Signaturformat

| `subFilter` | Was es ist |
|---|---|
| `SubFilter::CadesDetached` (Standard) | PAdES: abgesetztes CAdES-CMS mit `signing-certificate-v2`, das das Zertifikat bindet; der Signierzeitpunkt ist `/M`, kein CMS-Attribut. PAdES B-B, mit Zeitstempel B-T |
| `SubFilter::Pkcs7Detached` | das ältere `adbe.pkcs7.detached`; das CMS enthält `signing-time`, gesetzt auf denselben Zeitpunkt wie `/M` |

Beide sind abgesetzte CMS-`SignedData` mit eingebettetem Zertifikat des
Unterzeichners und eingebetteter Zertifikatskette. Den Standard sollte man
beibehalten, es sei denn, die Software eines Empfängers ist älter als PAdES.

## Zeitstempel

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

new SignatureConfig(
    signer: $signer,
    timestampAuthority: new TsaClient('https://freetsa.org/tsr'),
);
```

Der Signaturwert wird an den Zeitstempeldienst (TSA) nach RFC 3161
geschickt, und das Token wandert als Attribut `signature-time-stamp` in die
Signatur: Für den Signierzeitpunkt bürgt dann die TSA, nicht die Uhr des
Unterzeichners (PAdES B-T). `TsaClient` nimmt den Digest, HTTP-Basic-
Zugangsdaten, zusätzliche Header (einen API-Schlüssel), eine Policy-OID und
ein Timeout entgegen. Er prüft die Antwort — Status „granted“, den Imprint
und die angeforderte Nonce — und wirft andernfalls `TsaException`.

Anfragen laufen über den Stream-Wrapper von PHP mit TLS-Prüfung. Um einen
eigenen HTTP-Client, Proxy oder eine eigene Wiederholungsstrategie zu
verwenden, implementiert man `Transport\HttpTransport` und übergibt es als
`transport:`.

## GOST R 34.10-2012

GOST-Schlüssel (2012, 256 und 512 Bit) signieren genauso wie RSA, mit dem
Streebog-Digest und den Bezeichnern, die OpenSSL und CryptoPro verwenden.
In PHP muss die [Gost-Engine](https://github.com/gost-engine/engine) von
OpenSSL geladen sein — für OpenSSL 3 gibt es kein Paket, sie wird also aus
dem Quelltext gebaut und in `openssl.cnf` aktiviert. `docker/gost/` in
diesem Repository ist ein funktionierendes Rezept (die CI führt die
GOST-Tests darin aus). Ohne die Engine lässt sich ein GOST-Schlüssel nicht
lesen, und die Fehlermeldung sagt das.

## Signaturgröße

Der Platz für die Signatur wird in `/Contents` reserviert, bevor sie
existiert. Die Bibliothek schätzt ihn anhand der Zertifikate, der
Schlüsselgröße und danach, ob ein Zeitstempel angefordert wird. Bettet ein
Signer mehr ein als erwartet, schlägt das Signieren mit einer Meldung fehl,
die die benötigte Größe nennt; mit `contentsReserveBytes` lässt sich die
Schätzung überschreiben.

## Eine Signatur prüfen

Die Testsuite prüft jede Signatur mit Werkzeugen, die von der Bibliothek
unabhängig sind: `openssl cms -verify` bis zu einer Test-Stamm-CA,
`openssl ts -verify` für Zeitstempel und
[pyHanko](https://github.com/MatthiasValvekens/pyHanko) für das PDF als
Ganzes (`pyhanko sign validate --trust root.crt file.pdf`).

## Signaturfelder

In einem Dokument mit Layout ist ein Signaturfeld ein Block im Textfluss,
ähnlich einem Bild:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;

$doc = new Document(new Section([
    // … der Dokumentinhalt …
    new SignatureField('approval',
        widthPt: 250, heightPt: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('en'),
        alignment: Alignment::End),
]), signature: new SignatureConfig($certPem, $keyPem));
```

Auf einer von Hand gezeichneten Seite dient dafür
`Page::addSignatureField()` mit absoluten Koordinaten. Ein Signaturfeld
der Größe null (`width: 0, height: 0`) ist eine unsichtbare Signatur. Ein
Feld ohne Darstellung ist ein dünner, leerer Rahmen.

`FormField(type: 'signature')` und `Page::addFormField('signature', …)`
funktionieren weiterhin; sie zeichnen den leeren Rahmen.

## Der Stempel

`SignatureAppearance` ist ein Rahmen — Farbe und Breite der Umrandung,
abgerundete Ecken, Hintergrund, Innenabstand —, der der Reihe nach
gezeichnete Blöcke enthält:

| Block | Was er zeichnet |
|---|---|
| `TextBlock` | Zeilen mit Platzhaltern; Umbruch an Leerzeichen und Verkleinerung bis `minSizePt`, damit der Text passt, darüber hinaus wird abgeschnitten; Ausrichtung, vertikale Ausrichtung, Farbe, Fettdruck |
| `ImageBlock` | PNG oder JPEG — eine eingescannte Unterschrift, ein Siegel, ein Emblem; unter Beibehaltung der Proportionen eingepasst; PNG-Transparenz und `opacity` |
| `QrBlock` | ein QR-Code, z. B. ein Link auf eine Prüfseite; Platzhalter in den Daten erlaubt |

Die Position eines Blocks ist eine `Box` — Bruchteile des Innenbereichs,
Ursprung in der oberen linken Ecke:

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

`withFonts()`, `withVariables()` und `withBlocks()` liefern eine geänderte
Kopie zurück.

## Vorgefertigte Stempel

| Vorlage | Aussehen |
|---|---|
| `SignatureAppearance::electronicSignatureStamp('ru' \| 'en', $font, $boldFont, $color, $emblem)` | das gebräuchliche Zeichen für elektronische Signaturen: farbiger Rahmen mit abgerundeten Ecken, „DOCUMENT SIGNED WITH AN ELECTRONIC SIGNATURE“ (Dokument mit elektronischer Signatur signiert), danach Seriennummer des Zertifikats, Inhaber und Gültigkeitszeitraum; optionales Emblem links; etwa 250 × 75 pt |
| `SignatureAppearance::simple($font, $color)` | „Digitally signed by …“ (Digital signiert von …), das Datum sowie Grund und Ort, sofern angegeben |
| `SignatureAppearance::imageOnly($image, $borderColor)` | nur ein Bild |

## Platzhalter

| Platzhalter | Wert |
|---|---|
| `{cn}`, `{org}`, `{email}` | CN, O und E-Mail des Zertifikatsinhabers (Subject) |
| `{serial}` | Seriennummer des Zertifikats, Hex in Großbuchstaben |
| `{validFrom}`, `{validTo}` | Gültigkeit des Zertifikats, in `dateFormat` |
| `{issuer}` | der CN des Ausstellers |
| `{inn}`, `{innle}`, `{ogrn}`, `{ogrnip}`, `{snils}` | Kennungen russischer qualifizierter Zertifikate |
| `{signer}` | `SignatureConfig::$signerName`, sonst der CN des Zertifikats |
| `{signedAt}` | Signierzeitpunkt, in `dateTimeFormat` |
| `{reason}`, `{location}`, `{contact}` | aus `SignatureConfig` |
| jeder Schlüssel von `variables` | eigene Werte |

Eine Zeile, deren Platzhalter sämtlich leer ausfallen, wird weggelassen;
`'INN: {inn}'` verschwindet also bei einem Zertifikat ohne diese Angabe.

## Schriften

Der Stempeltext braucht eine Schrift mit den verwendeten Glyphen:

1. `SignatureAppearance::$font` / `$boldFont`, sofern gesetzt;
2. innerhalb eines Dokuments mit Layout — die Standardschrift und die
   Fettschrift des Dokuments (`Engine` `defaultFont` / `boldFont`);
3. andernfalls Helvetica, die nur WinAnsi (Latein) abdeckt. Text außerhalb
   davon wird mit einer Exception abgelehnt, statt als Zeichensalat
   gezeichnet zu werden.

Ohne Fettschrift wird fetter Text durch Nachziehen der Glyphenumrisse
fett gemacht.

## Mehrere Signaturfelder

Das zu signierende Feld wird über `SignatureConfig::$fieldName` benannt;
ohne Angabe wird das erste Signaturfeld signiert. Die übrigen Felder
bleiben für spätere Unterzeichner leer: Ihre Stempel werden nur mit den
eigenen `variables` gezeichnet und können so „Place for signature“ (Platz
für die Unterschrift) anzeigen. Ein unbekannter Feldname ist ein Fehler.

## Stempel auf jeder Seite

`repeatOnOtherPages: true` zeichnet den Stempel zusätzlich an derselben
Position auf jeder anderen Seite, als gewöhnlichen Inhalt, der als
Artefakt markiert ist. Nur das ursprüngliche Feld ist interaktiv — Viewer
und Validatoren behandeln mehrere Widgets eines Signaturfelds
unterschiedlich, daher sind die Kopien keine Widgets.

## PDF/A

Ein signiertes PDF/A-2b-Dokument mit Stempel wird bei jedem Push mit
veraPDF validiert. PDF/A verlangt, dass alle Schriften eingebettet sind;
daher muss ein Stempel unter PDF/A eine eingebettete Schrift haben (aus
der Darstellung oder aus dem Dokument); Helvetica wird dort abgelehnt.
`NeedAppearances` wird in signierten und PDF/A-Dokumenten weggelassen.
