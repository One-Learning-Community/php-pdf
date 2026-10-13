# Цифровая подпись

php-pdf подписывает документ в момент записи: отсоединённая подпись CMS в
формате **PAdES** (`ETSI.CAdES.detached`, ETSI EN 319 142) собирается самой
библиотекой и подписывается через расширение `openssl`, а плейсхолдеры
`/ByteRange` и `/Contents` патчатся после сборки файла. Поддерживаются ключи
RSA, ECDSA и ГОСТ Р 34.10-2012; со службой меток времени подпись достигает
уровня PAdES B-T. Поле подписи может показывать **штамп подписи**: рамку с
текстом, картинками и QR-кодом, заполняемую данными сертификата в момент
подписания документа.

- [Подпись](#подпись)
- [Ключи и сертификаты](#ключи-и-сертификаты)
- [Формат подписи](#формат-подписи)
- [Метка времени](#метка-времени)
- [ГОСТ Р 34.10-2012](#гост-р-3410-2012)
- [Размер подписи](#размер-подписи)
- [Подпись вне сервера](#подпись-вне-сервера)
- [Подпись готового PDF](#подпись-готового-pdf)
- [Сертифицирующая подпись и заблокированные поля](#сертифицирующая-подпись-и-заблокированные-поля)
- [Долгосрочная проверка](#долгосрочная-проверка)
- [Заполнение подписанной формы](#заполнение-подписанной-формы)
- [Зашифрованные PDF](#зашифрованные-pdf)
- [Проверка подписей](#проверка-подписей)
- [Проверка подписи](#проверка-подписи)
- [Откреплённая подпись](#откреплённая-подпись)
- [Поля подписи](#поля-подписи)
- [Штамп](#штамп)
- [Готовые штампы](#готовые-штампы)
- [Плейсхолдеры](#плейсхолдеры)
- [Шрифты](#шрифты)
- [Несколько полей подписи](#несколько-полей-подписи)
- [Штамп на каждой странице](#штамп-на-каждой-странице)
- [PDF/A](#pdfa)

## Подпись

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

`signedAt` задаёт время подписания; по умолчанию это момент записи. Один и
тот же момент попадает и в словарь подписи (`/M`), и в штамп.

## Ключи и сертификаты

Ключ можно передать тремя способами:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;

// 1. PEM-строки, вместе с промежуточными сертификатами, чтобы валидатор мог
//    построить путь до доверенного корня.
new SignatureConfig(
    certPem: $cert, privateKeyPem: $key, privateKeyPassphrase: 'secret',
    certificateChainPem: file_get_contents('intermediate.crt'),
);

// 2. Контейнер PKCS#12 (.p12 / .pfx) — ключ, сертификат и цепочка.
new SignatureConfig(signer: OpenSslSigner::fromPkcs12(file_get_contents('signer.p12'), 'password'));

// 3. Собственный Signer — токен PKCS#11, HSM, сервис подписи.
new SignatureConfig(signer: $mySigner);
```

`Signer` — небольшой интерфейс: `certificate()`, `chain()`,
`digestAlgorithm()` и `sign(string $data): string`. Библиотека передаёт в
`sign()` DER подписываемых атрибутов и ожидает обратно «сырое» значение
подписи, поэтому ключу вообще не нужно находиться в PHP.

Хеш выбирается по ключу: SHA-256 для RSA и P-256, SHA-384 для P-384,
SHA-512 для P-521, Стрибог для ГОСТ. Чтобы выбрать другой, передайте
`digest:` в `OpenSslSigner`.

## Формат подписи

| `subFilter` | Что это |
|---|---|
| `SubFilter::CadesDetached` (по умолчанию) | PAdES: отсоединённый CMS CAdES с `signing-certificate-v2`, связывающим сертификат; время подписания — это `/M`, а не атрибут CMS. PAdES B-B, B-T с меткой времени |
| `SubFilter::Pkcs7Detached` | более старый `adbe.pkcs7.detached`; CMS содержит `signing-time`, равный тому же моменту, что и `/M` |

Оба варианта — отсоединённый CMS `SignedData` со встроенными сертификатом
подписанта и цепочкой. Оставляйте значение по умолчанию, если только ПО
получателя не появилось раньше PAdES.

## Метка времени

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

new SignatureConfig(
    signer: $signer,
    timestampAuthority: new TsaClient('https://freetsa.org/tsr'),
);
```

Значение подписи отправляется в службу меток времени (TSA) по RFC 3161, а
полученный токен попадает в подпись как атрибут `signature-time-stamp`:
время подписания тогда подтверждает TSA, а не часы подписанта (PAdES B-T).
`TsaClient` принимает хеш, учётные данные HTTP basic, дополнительные
заголовки (API-ключ), OID политики и таймаут. Он проверяет ответ — статус
granted, отпечаток и nonce, который был запрошен, — а иначе бросает
`TsaException`.

Запросы идут через потоковую обёртку PHP с проверкой TLS. Чтобы
использовать свой HTTP-клиент, прокси или политику повторов, реализуйте
`Transport\HttpTransport` и передайте его как `transport:`.

## ГОСТ Р 34.10-2012

Ключи ГОСТ (2012, 256 и 512 бит) подписывают так же, как RSA, — с хешем
Стрибог и идентификаторами, которые используют OpenSSL и КриптоПро. В PHP
должен быть загружен [gost engine](https://github.com/gost-engine/engine)
для OpenSSL — для OpenSSL 3 он не поставляется пакетом, поэтому собирается
из исходников и включается в `openssl.cnf`. `docker/gost/` в этом
репозитории — рабочий рецепт (CI гоняет в нём тесты ГОСТ). Без engine ключ
ГОСТ прочитать нельзя, и ошибка сообщает об этом.

## Размер подписи

Место под подпись резервируется в `/Contents` до того, как она появится.
Библиотека оценивает его по сертификатам, размеру ключа и тому, запрошена
ли метка времени. Если подписант встраивает больше ожидаемого, подпись
завершается ошибкой с сообщением о необходимом размере; чтобы переопределить
оценку, задайте `contentsReserveBytes`.

## Подпись вне сервера

Когда ключ не может попасть на сервер — квалифицированный сертификат на
токене или в КриптоПро на машине пользователя, HSM, который подписывает по
запросу, облачный сервис подписи, — подписывайте в два шага:

```php
use Dskripchenko\PhpPdf\Pdf\Signature\External\{ExternalMode, ExternalSigner, PreparedSignature};

// 1. Выпустить документ с пустой подписью.
$prepared = $doc->prepareSignature(new SignatureConfig(
    // Сертификат, если он известен, попадает на штамп; его можно не указывать.
    signer: new ExternalSigner(certificatePem: $userCertificatePem),
    reason: 'Approval',
));
$state = $prepared->toJson();        // сохраните — сессия, кэш, база данных
$hash = $prepared->documentDigestHex(); // отправьте подписанту

// 2. Позже, с тем, что вернул подписант (здесь base64 CMS).
$pdf = PreparedSignature::fromJson($state)->completeBase64($cmsBase64);
```

| Режим | Подписант получает | Возвращает |
|---|---|---|
| `ExternalMode::Cms` (по умолчанию) | `documentDigest()` — или `signedContent()`, сами байты, для инструмента, подписывающего файлы | отсоединённый CMS (CAdES-BES) |
| `ExternalMode::RawSignature` | `dataToSign()` — подписываемые атрибуты, собранные библиотекой, — или `hashToSign()` для устройства, подписывающего хеш | сырое значение подписи |

`complete()` проверяет полученное перед встраиванием: CMS покрывает именно
этот документ (его message digest), он сделан сертификатом, переданным в
`prepareSignature()` (если он был), его подпись верна и он помещается в
зарезервированное место. Сертификаты, которых в CMS нет, добавляются из
подготовки; служба меток времени, переданный в `complete()`, ставит метку
времени на подпись. Сырое значение ГОСТ в порядке байт CryptoAPI
(little-endian) распознаётся и разворачивается.

Храните состояние там, где подписант не может его изменить: оно содержит
подготовленный документ, а его контрольная сумма ловит только повреждения,
но не подмену. После подготовки документ нельзя генерировать заново — подпись
покрывает именно эти байты.

### КриптоПро Browser plug-in

В браузере плагин подписывает хеш сертификатом пользователя и возвращает
CMS — набросок поверх API `cadesplugin` КриптоПро:

```js
// hashHex — documentDigestHex() с сервера; cert — сертификат пользователя,
// выбранный из хранилища (CAdESCOM.Store).
const hashed = await cadesplugin.CreateObjectAsync("CAdESCOM.HashedData");
await hashed.propset_Algorithm(cadesplugin.CADESCOM_HASH_ALGORITHM_CP_GOST_3411_2012_256);
await hashed.SetHashValue(hashHex);

const signer = await cadesplugin.CreateObjectAsync("CAdESCOM.CPSigner");
await signer.propset_Certificate(cert);
await signer.propset_Options(cadesplugin.CAPICOM_CERTIFICATE_INCLUDE_WHOLE_CHAIN);

const signedData = await cadesplugin.CreateObjectAsync("CAdESCOM.CadesSignedData");
const cmsBase64 = await signedData.SignHash(hashed, signer, cadesplugin.CADESCOM_CADES_BES);
// отправьте cmsBase64 обратно на сервер → completeBase64()
```

Берите алгоритм хеша, соответствующий ключу: `..._GOST_3411_2012_256` для
256-битного ключа ГОСТ, `..._GOST_3411_2012_512` для 512-битного. Передайте
сертификат в `ExternalSigner` (экспортируйте его из плагина через
`cert.Export(CADESCOM_ENCODE_BASE64)`), чтобы дайджест следовал ключу, а
штамп показывал владельца; для вычисления дайджеста Стрибог на сервере тоже
нужен gost engine. CMS, сделанный так, содержит атрибут `signing-time`;
валидаторы его принимают, хотя строгие профили PAdES ожидают время только в
`/M`.

## Подпись готового PDF

`PdfSigner` подписывает PDF, сделанный где-то ещё, — или уже подписанный —
способом **дописывания в конец файла (incremental update)**: исходные байты
остаются как есть, а подпись пишется после них, поэтому прежние подписи
по-прежнему покрывают ровно то, что подписали. Поддерживаются и классические
таблицы перекрёстных ссылок, и потоковые.

```php
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;

$signed = PdfSigner::fromBytes(file_get_contents('contract.pdf'))
    ->addField('approver', page: 0, x: 72, y: 72, width: 250, height: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('ru', $font))
    ->sign(new SignatureConfig(signer: $signer));

// Второй подписант — в уже имеющееся пустое поле; его штамп перерисовывается.
$twice = PdfSigner::fromBytes($signed)
    ->withAppearance(SignatureAppearance::simple($font))
    ->sign(new SignatureConfig(signer: $other, fieldName: 'counterparty'));
```

Подписывается поле `fieldName`, если оно задано (пустое поле подписи
документа или добавленное через `addField()`), иначе поле, добавленное через
`addField()`, иначе первое пустое поле подписи, иначе новое невидимое поле на
первой странице. `signatureFields()` перечисляет поля подписи документа и
показывает, подписаны ли они. `prepare()` вместо `sign()` возвращает
`PreparedSignature` для ключа вне сервера, как описано выше. Зашифрованные
PDF отклоняются.

## Сертифицирующая подпись и заблокированные поля

```php
use Dskripchenko\PhpPdf\Pdf\Signature\{DocMdpPermission, FieldLock};

new SignatureConfig(signer: $signer,
    certification: DocMdpPermission::FormFilling,   // что можно менять после подписи
    lockFields: FieldLock::only(['amount', 'date']), // поля, замороженные этой подписью
);
```

**Сертифицирующая подпись** (DocMDP) задаёт, что можно менять после неё:
`NoChanges`, `FormFilling` (заполнение форм и подписание) или
`FormFillingAndAnnotations`. Сертифицировать документ может только первая
подпись. После сертификации дальнейшие подписи ставятся только в пустые поля
подписи, уже имеющиеся в документе, — добавление поля меняет страницу, а
сертификация это запрещает, — поэтому расположите их в сертифицирующем
обновлении через `PdfSigner::addEmptyField()` (или в генерируемом документе).
Документ, сертифицированный с `NoChanges`, дальнейшие подписи не принимает.

`FieldLock::all()`, `only([...])` или `allExcept([...])` блокирует поля формы
(FieldMDP): их последующее изменение делает подпись недействительной.

Оба механизма работают и для генерируемых документов (`Pdf\Document::sign()`),
и для готовых (`PdfSigner`).

## Долгосрочная проверка

Подпись можно проверить спустя годы, только если валидатор всё ещё находит
сертификаты и статус их отзыва — OCSP-ответчики и списки CRL исчезают вместе
с УЦ. PAdES хранит их в самом документе:

```php
$bt   = $doc->toBytes();                       // B-T: подпись с меткой времени
$blt  = PdfSigner::fromBytes($bt)->addValidationData();   // B-LT: + DSS
$blta = PdfSigner::fromBytes($blt)->timestamp($tsa);      // B-LTA: + метка времени документа
```

`addValidationData()` собирает все сертификаты подписей документа и их токенов
меток времени, скачивает недостающих издателей (AIA caIssuers — корень,
который цепочка опускает), опрашивает OCSP-ответчик каждого сертификата,
а при неудаче обращается к его CRL, и записывает **хранилище данных проверки
(DSS)** как дописывание в конец файла. Передайте собственный
`RevocationSource` (кэш, хранилище) или данные через `extra:` для встраивания;
`fetchRevocation: false` встраивает только сертификаты. Повторный вызов
дополняет DSS.

`timestamp()` добавляет **метку времени документа** (`ETSI.RFC3161`) на весь
файл, включая DSS. Повторяйте `addValidationData()` + `timestamp()` до
истечения срока сертификата последней метки, чтобы продлить архив.

## Заполнение подписанной формы

`ExistingFormFiller::toBytes(incremental: true)` записывает заполненные значения
как дописывание в конец файла (incremental update), а не переписывает файл, поэтому
подписи остаются действительными, а документ, сертифицированный для заполнения
форм, остаётся сертифицированным:

```php
$filled = ExistingFormFiller::fromBytes($signedPdf)
    ->setValue('amount', '100')
    ->toBytes(incremental: true);
```

Документ, сертифицированный с `NoChanges`, такой вызов отклоняет, а при любой
сертификации `flatten()` и штампы-изображения (изменения страниц) отклоняются.
Без `incremental: true` заполнитель по-прежнему переписывает весь файл, что
делает подписи недействительными.

## Зашифрованные PDF

Передайте пароль, открывающий файл: `PdfSigner::fromBytes($pdf,
$password)`, `ExistingFormFiller::fromBytes($pdf, $password)`,
`SignatureValidator::fromBytes($pdf, $password)`. Обновление шифруется
собственным ключом файла (RC4, AES-128, AES-256), само значение подписи, как
требует формат, остаётся открытым, а `/Encrypt` и идентификатор документа
сохраняются. Файл только с паролем владельца открывается пустым паролем.

## Проверка подписей

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\{SignatureValidator, TrustStore};

$report = SignatureValidator::fromBytes($pdf)
    ->validate(TrustStore::fromPem('root.crt'));   // или TrustStore::system()

$report->allValid();
foreach ($report->signatures as $s) {
    $s->fieldName; $s->isValid(); $s->errors; $s->warnings;
    $s->signer; $s->chain; $s->timestampTime; $s->revocation; $s->modifications;
}
$original = SignatureValidator::fromBytes($pdf)->signedRevision('approver');
```

Для каждой подписи и метки времени документа:

| Проверка | Что обнаруживает |
|---|---|
| `/ByteRange` начинается с 0, не выходит за файл, его разрыв — ровно `<hex>`-значение подписи, подписанные байты заканчиваются на `%%EOF` ревизии, а словарь подписи этой ревизии содержит то же значение | подмену подписи (signature wrapping), универсальную подделку подписи (universal signature forgery), подменённый `/Contents` |
| дайджест сообщения совпадает с подписанными байтами; подпись проверяется ключом подписанта; `signing-certificate-v2` указывает на этот сертификат | подделку данных, подменённый сертификат |
| метка времени подписи покрывает это значение подписи, проверяется, а её TSA строит цепочку до доверенного корня — цепочка оценивается на этот момент времени | метки времени, поставленные задним числом или украденные |
| подписант строит цепочку до якоря доверия, каждое звено проверено ключом своего издателя и действительно на нужный момент | недоверенных или просроченных подписантов |
| ответы OCSP и CRL из DSS (их собственные подписи проверяются) либо полученные через `RevocationSource` | отозванных подписантов — отзыв до подписания считается ошибкой, после подписания с меткой времени — предупреждением |
| всё, дописанное после подписи, объект за объектом: подписание (подписи, метки времени, DSS), заполнение форм, аннотации и всё остальное — «всё остальное» не допускается никогда | атаки через инкрементальное сохранение (incremental-saving attacks), теневые атаки (shadow attacks: подменённое содержимое страницы, наложения) |
| уровень DocMDP сертификации и блокировка FieldMDP подписи относительно этих изменений | изменения, которые сертификация или блокировка запрещают |

`modifications` сообщает достигнутый уровень (`ModificationLevel::None`,
`Signing`, `FormFilling`, `Annotations`, `Other`). Подтверждающая подпись
(approval signature), за которой следуют аннотации, остаётся действительной, но
сообщает об этом; при сертификации «заполнение форм» те же аннотации делают её
недействительной.

## Проверка подписи

Набор тестов проверяет каждую подпись инструментами, не зависящими от
библиотеки: `openssl cms -verify` до тестового корневого УЦ,
`openssl ts -verify` для меток времени и
[pyHanko](https://github.com/MatthiasValvekens/pyHanko) для PDF в целом
(`pyhanko sign validate --trust root.crt file.pdf`), в том числе с несколькими
подписантами и сертификацией. Долгосрочная проверка контролируется профилями
pyHanko PAdES-LT / LTA офлайн: валидатору передаётся только текущий статус TSA,
поэтому данные об отзыве сертификата подписанта должны браться из DSS
(`scripts/conformance/pades-ltv-check.py`).

## Откреплённая подпись

`.p7s` рядом с файлом: PDF остаётся как есть, подпись — отдельный CMS над
его байтами (любыми, не только PDF):

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\DetachedSignature;

$p7s = DetachedSignature::sign($pdf, $signer, timestampAuthority: $tsa);   // DER
$report = DetachedSignature::verify($pdf, $p7s, TrustStore::fromPem('root.crt'));
$report->isValid(); $report->signer; $report->timestampTime;

$p7s = DetachedSignature::addTimestamp($cmsFromCryptoPro, $tsa);  // CAdES-BES → CAdES-T
```

`sign()` пишет CAdES-BES: `content-type`, `message-digest`, `signing-time`
и `signing-certificate-v2`, сертификат подписанта и цепочка внутри; с
сервером меток времени — CAdES-T. `verify()` возвращает тот же
`SignatureReport`, что и проверка PDF: дайджест и значение подписи,
привязку сертификата, метку времени, цепочку (на момент метки времени) и,
если передан `RevocationSource`, отзыв. Принимает DER, BER, base64 и PEM;
присоединённая подпись должна нести ровно переданное содержимое; подпись
без подписанных атрибутов (`openssl cms -noattr`) проверяется, с
предупреждением, что ни время, ни сертификат к ней не привязаны.

## Поля подписи

В документе с вёрсткой поле подписи — это блок в потоке, как изображение:

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;

$doc = new Document(new Section([
    // … тело документа …
    new SignatureField('approval',
        widthPt: 250, heightPt: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('en'),
        alignment: Alignment::End),
]), signature: new SignatureConfig($certPem, $keyPem));
```

На странице, нарисованной вручную, используется `Page::addSignatureField()`
с абсолютными координатами. Поле нулевого размера (`width: 0, height: 0`) —
невидимая подпись. Поле без оформления — тонкая пустая рамка.

`FormField(type: 'signature')` и `Page::addFormField('signature', …)`
продолжают работать; они рисуют пустую рамку.

## Штамп

`SignatureAppearance` — это рамка (цвет и толщина границы, скруглённые углы,
фон, отступы), внутри которой по порядку рисуются блоки:

| Блок | Что рисует |
|---|---|
| `TextBlock` | строки с плейсхолдерами; переносятся по пробелам и уменьшаются до `minSizePt`, чтобы поместиться, а сверх этого обрезаются; выравнивание, вертикальное выравнивание, цвет, жирность |
| `ImageBlock` | PNG или JPEG — скан подписи, печать, эмблема; вписывается с сохранением пропорций; прозрачность PNG и `opacity` |
| `QrBlock` | QR-код, например ссылка на страницу проверки; в данных допустимы плейсхолдеры |

Место блока задаётся через `Box` — доли внутренней области, начало отсчёта в
левом верхнем углу:

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

`withFonts()`, `withVariables()` и `withBlocks()` возвращают изменённую копию.

## Готовые штампы

| Пресет | Вид |
|---|---|
| `SignatureAppearance::electronicSignatureStamp('ru' \| 'en', $font, $boldFont, $color, $emblem)` | привычная отметка об электронной подписи: цветная скруглённая рамка, «ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ», затем серийный номер сертификата, владелец и срок действия; слева опциональная эмблема; около 250 × 75 pt |
| `SignatureAppearance::simple($font, $color)` | «Digitally signed by …», дата, а также причина и место, если заданы |
| `SignatureAppearance::imageOnly($image, $borderColor)` | только картинка |

## Плейсхолдеры

| Плейсхолдер | Значение |
|---|---|
| `{cn}`, `{org}`, `{email}` | CN, O и e-mail субъекта сертификата |
| `{serial}` | серийный номер сертификата, hex в верхнем регистре |
| `{validFrom}`, `{validTo}` | срок действия сертификата, в формате `dateFormat` |
| `{issuer}` | CN издателя |
| `{inn}`, `{innle}`, `{ogrn}`, `{ogrnip}`, `{snils}` | идентификаторы российских квалифицированных сертификатов |
| `{signer}` | `SignatureConfig::$signerName`, иначе CN сертификата |
| `{signedAt}` | время подписания, в формате `dateTimeFormat` |
| `{reason}`, `{location}`, `{contact}` | из `SignatureConfig` |
| любой ключ `variables` | ваши собственные значения |

Строка, все плейсхолдеры которой оказались пустыми, отбрасывается, поэтому
`'INN: {inn}'` исчезает для сертификата без ИНН.

## Шрифты

Тексту штампа нужен шрифт с используемыми им глифами:

1. `SignatureAppearance::$font` / `$boldFont`, если заданы;
2. внутри документа с вёрсткой — шрифт документа по умолчанию и его жирный
   шрифт (`Engine` `defaultFont` / `boldFont`);
3. иначе Helvetica, покрывающая только WinAnsi (латиницу). Текст за её
   пределами отклоняется исключением, а не рисуется мусором.

Без жирного шрифта жирный текст получается утолщением контуров глифов
обводкой.

## Несколько полей подписи

Поле, которым нужно подписать, задаётся через `SignatureConfig::$fieldName`;
без него подписывается первое поле подписи. Остальные поля остаются пустыми
для последующих подписантов: их штампы рисуются только с их собственными
`variables`, поэтому могут сообщать «Место для подписи». Неизвестное имя
поля — ошибка.

## Штамп на каждой странице

`repeatOnOtherPages: true` рисует штамп в той же позиции и на всех остальных
страницах — как обычное содержимое, помеченное артефактом. Интерактивно
только исходное поле: просмотрщики и валидаторы по-разному обрабатывают
несколько виджетов одного поля подписи, поэтому копии не являются виджетами.

## PDF/A

Подписанный документ PDF/A-2b со штампом проверяется veraPDF при каждом
push. PDF/A требует встроить все шрифты, поэтому у штампа под PDF/A должен
быть встроенный шрифт (из оформления или из документа); Helvetica там
отклоняется. `NeedAppearances` в подписанных документах и документах PDF/A
не выставляется.
