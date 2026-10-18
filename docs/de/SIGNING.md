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
- [Schlüssel hinter CMS-Software](#schlüssel-hinter-cms-software)
- [Signieren außerhalb des Servers](#signieren-außerhalb-des-servers)
- [Bestehende PDF signieren](#bestehende-pdf-signieren)
- [Zertifizierung und gesperrte Felder](#zertifizierung-und-gesperrte-felder)
- [Langzeitvalidierung](#langzeitvalidierung)
- [Signiertes Formular ausfüllen](#signiertes-formular-ausfüllen)
- [Verschlüsselte PDFs](#verschlüsselte-pdfs)
- [Signaturen validieren](#signaturen-validieren)
- [Eine Signatur prüfen](#eine-signatur-prüfen)
- [Abgetrennte Signaturen](#abgetrennte-signaturen)
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

Fertige `Signer` liefern Begleitpakete:
[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) signiert mit
einem Schlüssel auf einem HSM, einer Smartcard oder einem USB-Token über
PKCS#11 (`Pkcs11Signer::open()`), und
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) mit
einem Schlüssel in AWS KMS (`AwsKmsSigner::create()`), Google Cloud KMS
(`GoogleKmsSigner`) oder Azure Key Vault
(`AzureKeyVaultSigner::fromCertificate()`):

```php
use Dskripchenko\PhpPdfCloudKms\Aws\AwsKmsSigner;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;

$signer = Pkcs11Signer::open('/usr/lib/softhsm/libsofthsm2.so', pin: $pin, keyLabel: 'signing-key');
$signer = AwsKmsSigner::create('alias/pdf-signing', $certificatePem, region: 'eu-central-1');
```

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

## Schlüssel hinter CMS-Software

Manche Schlüssel sind nur über Software erreichbar, die eine ganze CMS
statt eines nackten Signaturwerts liefert: CryptoPro CSP auf dem Server
(`CadesSignedData.SignHash`, `cryptcp`), ein Signaturdienst, ein HSM-Frontend.
Implementieren Sie `CmsSigner` und signieren Sie in einem Aufruf wie mit
einem Schlüssel im Prozess — ohne manuelles Vorbereiten und Abschließen.

Für CryptoPro CSP auf dem Server liefert
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
`CryptoProCadesSigner`, einen fertigen `CmsSigner` über die PHP-Erweiterung
von CryptoPro:

```php
use Dskripchenko\PhpPdfCryptoPro\CryptoProCadesSigner;

$doc->sign(new SignatureConfig(signer: CryptoProCadesSigner::fromThumbprint('3A 5F … 9C', pin: $pin)));
```

Implementieren Sie `CmsSigner` selbst nur für andere Werkzeuge — `cryptcp`,
einen Signaturdienst:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\{Certificate, CmsSigner, DigestAlgorithm};

final class CryptcpSigner implements CmsSigner
{
    public function __construct(private string $certificatePem, private string $thumbprint) {}

    public function certificate(): Certificate { return Certificate::fromPem($this->certificatePem); }
    public function chain(): array { return []; }
    public function digestAlgorithm(): DigestAlgorithm { return DigestAlgorithm::defaultFor($this->certificate()); }

    public function signCms(string $content, string $digest): string
    {
        // Signaturwerkzeug oder CSP-API aufrufen, CMS zurückgeben
    }
}

$doc->sign(new SignatureConfig(signer: new CryptcpSigner($certPem, $thumbprint)));
```

`signCms()` erhält die signierten Bytes und ihren Hash nach
`digestAlgorithm()` und liefert eine CMS SignedData in DER oder BER,
abgelöst oder eingebettet. Vor dem Einbetten prüft die Bibliothek sie wie
`complete()` (unten): sie deckt genau dieses Dokument ab, wurde mit
`certificate()` erstellt, ihre Signatur ist gültig; fehlende Zertifikate
kommen aus `chain()`, und eine `timestampAuthority` in der Konfiguration
versieht sie mit einem Zeitstempel. Das Zertifikat erscheint im Stempel.
Funktioniert mit `Pdf\Document::sign()`, `signature:` eines gesetzten
Dokuments und `PdfSigner::sign()`.

## Signieren außerhalb des Servers

Ein HSM oder ein Cloud-KMS, das der Server direkt aufrufen kann, braucht
keine zwei Schritte — nutzen Sie die
[PKCS#11- oder KMS-Signer](#schlüssel-und-zertifikate) oben. Zweistufiges
Signieren ist für Schlüssel, die nur der Nutzer erreicht: ein qualifiziertes
Zertifikat auf einem Token oder in CryptoPro auf dem Rechner des Nutzers
(ein Browser-Plug-in) oder ein Signaturdienst, der später antwortet. Es wird
in zwei Schritten signiert:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\External\{ExternalMode, ExternalSigner, PreparedSignature};

// 1. Das Dokument mit einer leeren Signatur ausgeben.
$prepared = $doc->prepareSignature(new SignatureConfig(
    // Das Zertifikat, falls bekannt, kommt auf den Stempel; es darf fehlen.
    signer: new ExternalSigner(certificatePem: $userCertificatePem),
    reason: 'Approval',
));
$state = $prepared->toJson();        // aufbewahren — Session, Cache, Datenbank
$hash = $prepared->documentDigestHex(); // an den Signierenden senden

// 2. Später, mit dem, was der Signierende zurückgibt (hier base64-CMS).
$pdf = PreparedSignature::fromJson($state)->completeBase64($cmsBase64);
```

| Modus | Der Signierende erhält | Er gibt zurück |
|---|---|---|
| `ExternalMode::Cms` (Standard) | `documentDigest()` — oder `signedContent()`, die Bytes selbst, für ein Werkzeug, das Dateien signiert | ein abgesetztes CMS (CAdES-BES) |
| `ExternalMode::RawSignature` | `dataToSign()` — die von der Bibliothek gebauten signierten Attribute — oder `hashToSign()` für ein Gerät, das einen Hash signiert | den rohen Signaturwert |

`complete()` prüft das Zurückgegebene vor dem Einbetten: Das CMS deckt genau
dieses Dokument ab (sein Message Digest), es wurde mit dem an
`prepareSignature()` übergebenen Zertifikat erstellt (falls eines
übergeben wurde), seine Signatur ist gültig und es passt in den
reservierten Platz. Zertifikate, die dem CMS fehlen, werden aus der
Vorbereitung ergänzt; eine an `complete()` übergebene Zeitstempelstelle
versieht die Signatur mit einem Zeitstempel. Ein roher GOST-Wert in der
Little-Endian-Reihenfolge von CryptoAPI wird erkannt und umgedreht.

Bewahren Sie den Zustand dort auf, wo der Signierende ihn nicht ändern
kann: Er enthält das vorbereitete Dokument, und seine Prüfsumme erkennt nur
Beschädigung, keine Manipulation. Nach der Vorbereitung darf das Dokument
nicht neu erzeugt werden — die Signatur deckt genau diese Bytes ab.

### CryptoPro Browser plug-in

Im Browser signiert das Plug-in den Hash mit dem Zertifikat des Nutzers und
gibt ein CMS zurück — eine Skizze auf Basis der `cadesplugin`-API von
CryptoPro:

```js
// hashHex — documentDigestHex() vom Server; cert — das Zertifikat des Nutzers,
// aus dem Speicher gewählt (CAdESCOM.Store).
const hashed = await cadesplugin.CreateObjectAsync("CAdESCOM.HashedData");
await hashed.propset_Algorithm(cadesplugin.CADESCOM_HASH_ALGORITHM_CP_GOST_3411_2012_256);
await hashed.SetHashValue(hashHex);

const signer = await cadesplugin.CreateObjectAsync("CAdESCOM.CPSigner");
await signer.propset_Certificate(cert);
await signer.propset_Options(cadesplugin.CAPICOM_CERTIFICATE_INCLUDE_WHOLE_CHAIN);

const signedData = await cadesplugin.CreateObjectAsync("CAdESCOM.CadesSignedData");
const cmsBase64 = await signedData.SignHash(hashed, signer, cadesplugin.CADESCOM_CADES_BES);
// cmsBase64 zurück per POST senden → completeBase64()
```

Verwenden Sie den zum Schlüssel passenden Hash-Algorithmus:
`..._GOST_3411_2012_256` für einen 256-Bit-GOST-Schlüssel,
`..._GOST_3411_2012_512` für 512. Übergeben Sie das Zertifikat an
`ExternalSigner` (exportieren Sie es aus dem Plug-in mit
`cert.Export(CADESCOM_ENCODE_BASE64)`), damit der Digest dem Schlüssel
folgt und der Stempel den Inhaber zeigt; zum Berechnen des Streebog-Digests
wird die Gost-Engine auch auf dem Server benötigt. Das so erzeugte CMS
enthält ein `signing-time`-Attribut; Validatoren akzeptieren es, auch wenn
strenge PAdES-Profile die Zeit nur in `/M` erwarten.

## Bestehende PDF signieren

`PdfSigner` signiert ein anderswo erzeugtes — oder bereits signiertes — PDF
als **inkrementelles Update**: Die Originalbytes bleiben unverändert, die
Signatur wird dahinter geschrieben, sodass frühere Signaturen weiterhin genau
das abdecken, was sie signiert haben. Klassische Cross-Reference-Tabellen und
Cross-Reference-Streams werden beide verarbeitet.

```php
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;

$signed = PdfSigner::fromBytes(file_get_contents('contract.pdf'))
    ->addField('approver', page: 0, x: 72, y: 72, width: 250, height: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('ru', $font))
    ->sign(new SignatureConfig(signer: $signer));

// Ein zweiter Signierer, in ein bereits vorhandenes leeres Feld; sein Stempel wird neu gezeichnet.
$twice = PdfSigner::fromBytes($signed)
    ->withAppearance(SignatureAppearance::simple($font))
    ->sign(new SignatureConfig(signer: $other, fieldName: 'counterparty'));
```

Signiert wird das Feld `fieldName`, falls angegeben (ein leeres
Signaturfeld des Dokuments oder das mit `addField()` hinzugefügte), sonst das
mit `addField()` hinzugefügte Feld, sonst das erste leere Signaturfeld, sonst
ein neues unsichtbares Feld auf der ersten Seite. `signatureFields()` listet
die Signaturfelder des Dokuments auf und zeigt, ob sie signiert sind.
`prepare()` statt `sign()` liefert eine `PreparedSignature` für einen
Schlüssel außerhalb des Servers, wie oben. Ein verschlüsseltes PDF wird mit
seinem Passwort signiert, siehe [Verschlüsselte PDFs](#verschlüsselte-pdfs).

## Zertifizierung und gesperrte Felder

```php
use Dskripchenko\PhpPdf\Pdf\Signature\{DocMdpPermission, FieldLock};

new SignatureConfig(signer: $signer,
    certification: DocMdpPermission::FormFilling,   // was danach geändert werden darf
    lockFields: FieldLock::only(['amount', 'date']), // durch diese Signatur eingefrorene Felder
);
```

Eine **Zertifizierungssignatur** (DocMDP) legt fest, was danach geändert
werden darf: `NoChanges`, `FormFilling` (Formulare ausfüllen und signieren)
oder `FormFillingAndAnnotations`. Nur die erste Signatur eines Dokuments kann
es zertifizieren. Nach der Zertifizierung kommen weitere Signaturen nur in
die leeren Signaturfelder, die das Dokument bereits hat — ein neues Feld
ändert die Seite, was die Zertifizierung verbietet —, legen Sie sie daher im
zertifizierenden Update mit `PdfSigner::addEmptyField()` an (oder im
erzeugten Dokument). Ein mit `NoChanges` zertifiziertes Dokument lehnt
weitere Signaturen ab.

`FieldLock::all()`, `only([...])` oder `allExcept([...])` sperrt
Formularfelder (FieldMDP): Werden sie danach geändert, ist die Signatur
ungültig.

Beides funktioniert für erzeugte Dokumente (`Pdf\Document::sign()`) und für
bestehende (`PdfSigner`).

## Langzeitvalidierung

Eine Signatur lässt sich Jahre später nur prüfen, wenn der Validator die
Zertifikate und ihren Sperrstatus noch findet — OCSP-Responder und CRLs
verschwinden mit der CA. PAdES legt sie im Dokument ab:

```php
$bt   = $doc->toBytes();                       // B-T: mit Zeitstempel signiert
$blt  = PdfSigner::fromBytes($bt)->addValidationData();   // B-LT: + DSS
$blta = PdfSigner::fromBytes($blt)->timestamp($tsa);      // B-LTA: + Dokumentzeitstempel
```

`addValidationData()` sammelt alle Zertifikate der Signaturen des Dokuments
und ihrer Zeitstempel-Token, lädt fehlende Aussteller nach (AIA caIssuers —
das Root-Zertifikat, das eine Kette auslässt), fragt den OCSP-Responder jedes
Zertifikats ab, greift ersatzweise auf dessen CRL zurück und schreibt den
Document Security Store (DSS) als inkrementelles Update. Übergeben Sie eine
eigene `RevocationSource` (Cache, Speicher) oder Daten über `extra:` zum
Einbetten; `fetchRevocation: false` bettet nur Zertifikate ein. Ein erneuter
Aufruf erweitert den DSS.

`timestamp()` fügt einen Dokumentzeitstempel (`ETSI.RFC3161`) über die
gesamte Datei hinzu, einschließlich des DSS. Wiederholen Sie
`addValidationData()` + `timestamp()`, bevor das Zertifikat des letzten
Zeitstempels abläuft, um das Archiv zu verlängern.

## Signiertes Formular ausfüllen

`ExistingFormFiller::toBytes(incremental: true)` schreibt die ausgefüllten Werte
als inkrementelles Update, statt die Datei neu zu schreiben, sodass Signaturen
gültig bleiben — und ein für das Ausfüllen von Formularen zertifiziertes
Dokument zertifiziert bleibt:

```php
$filled = ExistingFormFiller::fromBytes($signedPdf)
    ->setValue('amount', '100')
    ->toBytes(incremental: true);
```

Ein mit `NoChanges` zertifiziertes Dokument lehnt das ab, und unter jeder
Zertifizierung werden `flatten()` und Bildstempel (Seitenänderungen)
abgelehnt. Ohne `incremental: true` schreibt der Filler weiterhin die gesamte
Datei neu, wodurch Signaturen ungültig werden.

## Verschlüsselte PDFs

Übergeben Sie das Passwort, das die Datei öffnet: `PdfSigner::fromBytes($pdf,
$password)`, `ExistingFormFiller::fromBytes($pdf, $password)`,
`SignatureValidator::fromBytes($pdf, $password)`. Das Update wird mit dem
eigenen Schlüssel der Datei verschlüsselt (RC4, AES-128, AES-256), der
Signaturwert selbst bleibt, wie das Format es verlangt, im Klartext, und
`/Encrypt` sowie die Dokument-ID bleiben erhalten. Eine Datei mit nur einem
Owner-Passwort lässt sich mit einem leeren Passwort öffnen.

## Signaturen validieren

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\{SignatureValidator, TrustStore};

$report = SignatureValidator::fromBytes($pdf)
    ->validate(TrustStore::fromPem('root.crt'));   // oder TrustStore::system()

$report->allValid();
foreach ($report->signatures as $s) {
    $s->fieldName; $s->isValid(); $s->errors; $s->warnings;
    $s->signer; $s->chain; $s->timestampTime; $s->revocation; $s->modifications;
}
$original = SignatureValidator::fromBytes($pdf)->signedRevision('approver');
```

Für jede Signatur und jeden Dokumentzeitstempel:

| Prüfung | Was sie erkennt |
|---|---|
| `/ByteRange` beginnt bei 0, bleibt innerhalb der Datei, seine Lücke ist genau der `<hex>`-Signaturwert, die signierten Bytes enden am `%%EOF` einer Revision, und das Signaturwörterbuch dieser Revision trägt denselben Wert | Signature Wrapping, Universal Signature Forgery, ein ausgetauschtes `/Contents` |
| der Message Digest stimmt mit den signierten Bytes überein; die Signatur verifiziert mit dem Schlüssel des Unterzeichners; `signing-certificate-v2` nennt dieses Zertifikat | Manipulation, ein untergeschobenes Zertifikat |
| ein Signatur-Zeitstempel deckt diesen Signaturwert ab, verifiziert, und seine TSA führt zu einem vertrauenswürdigen Root — die Kette wird dann zu diesem Zeitpunkt beurteilt | rückdatierte oder gestohlene Zeitstempel |
| der Unterzeichner führt zu einem Vertrauensanker, jedes Glied mit dem Schlüssel seines Ausstellers verifiziert und zum jeweiligen Zeitpunkt gültig | nicht vertrauenswürdige oder abgelaufene Unterzeichner |
| OCSP-Antworten und CRLs aus dem DSS (ihre eigenen Signaturen werden verifiziert) oder über eine `RevocationSource` abgerufen | gesperrte Unterzeichner — Sperrung vor dem Signieren ist ein Fehler, nach einer zeitgestempelten Signatur eine Warnung |
| alles, was nach der Signatur angehängt wurde, Objekt für Objekt: Signieren (Signaturen, Zeitstempel, DSS), Formularausfüllung, Annotationen oder alles andere — „alles andere“ ist nie erlaubt | Incremental-Saving-Angriffe, Shadow-Angriffe (ersetzter Seiteninhalt, Overlays) |
| die DocMDP-Stufe einer Zertifizierung und die FieldMDP-Sperre einer Signatur gegenüber diesen Änderungen | Änderungen, die die Zertifizierung oder Sperre verbietet |

`modifications` meldet die erreichte Stufe (`ModificationLevel::None`,
`Signing`, `FormFilling`, `Annotations`, `Other`). Eine Freigabesignatur, auf
die Annotationen folgen, bleibt gültig, weist aber darauf hin; unter einer
Zertifizierung „Formularausfüllung“ machen dieselben Annotationen sie ungültig.

## Eine Signatur prüfen

Die Testsuite prüft jede Signatur mit Werkzeugen, die von der Bibliothek
unabhängig sind: `openssl cms -verify` bis zu einer Test-Stamm-CA,
`openssl ts -verify` für Zeitstempel und
[pyHanko](https://github.com/MatthiasValvekens/pyHanko) für das PDF als
Ganzes (`pyhanko sign validate --trust root.crt file.pdf`), auch mit mehreren
Signierern und Zertifizierung. Die Langzeitvalidierung wird mit den
PAdES-LT-/LTA-Profilen von pyHanko offline geprüft: Der Validator erhält nur
den aktuellen Status der TSA, die Sperrdaten des Signierers müssen also aus
dem DSS stammen (`scripts/conformance/pades-ltv-check.py`).

## Abgetrennte Signaturen

Eine `.p7s` neben einer Datei — das PDF bleibt unverändert, die Signatur ist
ein eigenes CMS über seine Bytes (beliebige Bytes, nicht nur PDF):

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\DetachedSignature;

$p7s = DetachedSignature::sign($pdf, $signer, timestampAuthority: $tsa);   // DER
$report = DetachedSignature::verify($pdf, $p7s, TrustStore::fromPem('root.crt'));
$report->isValid(); $report->signer; $report->timestampTime;

$p7s = DetachedSignature::addTimestamp($cmsFromCryptoPro, $tsa);  // CAdES-BES → CAdES-T
```

`sign()` schreibt CAdES-BES: `content-type`, `message-digest`,
`signing-time` und `signing-certificate-v2`, Zertifikat und Kette des
Unterzeichners eingebettet; mit einem Zeitstempeldienst ist es CAdES-T.
`$signer` darf auch ein `CmsSigner` sein — CryptoPro CSP auf dem Server, ein
Signaturdienst: sein CMS wird wie bei einem PDF geprüft (deckt genau diese
Bytes ab, mit dem Zertifikat des Unterzeichners erstellt, verifiziert) und
kommt abgelöst zurück, mit Kette und Zeitstempel.
`verify()` liefert denselben `SignatureReport` wie der PDF-Validator: Digest
und Signaturwert, die Zertifikatsbindung, den Zeitstempel, die Kette (zum
Zeitpunkt des Zeitstempels beurteilt) und, mit einer `RevocationSource`, den
Sperrstatus. Akzeptiert werden DER, BER, Base64 und PEM; eine angehängte
Signatur muss genau den übergebenen Inhalt tragen; eine Signatur ohne
signierte Attribute (`openssl cms -noattr`) wird geprüft, mit der Warnung,
dass weder Zeit noch Zertifikat an sie gebunden sind.

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
