# Digitale Signaturen

php-pdf signiert ein Dokument beim Schreiben: PKCS#7-Detached-Signatur
(`adbe.pkcs7.detached`) über die Erweiterung `openssl`, wobei die
Platzhalter für `/ByteRange` und `/Contents` nach dem Zusammenbau der Datei
gepatcht werden. Das Signaturfeld kann einen **Signaturstempel** anzeigen:
einen Rahmen mit Text, Bildern und einem QR-Code, der beim Signieren des
Dokuments aus dem Zertifikat befüllt wird.

- [Signieren](#signieren)
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
