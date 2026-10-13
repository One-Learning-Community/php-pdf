# План: электронная подпись PDF — штамп, PAdES, внешняя подпись, инкрементальные обновления

Статус: **этап A выпущен в 1.11.0** (PR #2), **этап B выпущен в 1.12.0** (PR #3), C–D — впереди · База: `php-pdf` 1.10.0 · Порядок этапов: A → B → C → D
Внедрение в printable — отдельный этап после выпуска библиотеки, здесь не рассматривается.

## 1. Исходное состояние (1.10.0)

| Что | Где | Проблема |
|---|---|---|
| `Pdf\Document::sign(SignatureConfig)` | `src/Pdf/Document.php:528` | подписывает только при генерации и только первое поле подписи |
| Внешний вид поля | `buildSignatureAppearance()` `src/Pdf/Document.php:2514` | пустая рамка 0.5pt, содержимого штампа нет |
| CMS | `Writer::applyPkcs7Signature()` `src/Pdf/Writer.php:621` | `openssl_pkcs7_sign` → S/MIME PKCS#7, `adbe.pkcs7.detached`, в CMS лишний `signingTime`, нет ESS signing-certificate-v2, цепочка не вкладывается (`extracerts` не передан) |
| Ключ | `SignatureConfig::$privateKeyPem` | закрытый ключ обязан лежать на сервере в PEM — для КЭП (токен, КриптоПро CSP, HSM, облако) это неприменимо |
| `/Contents` | `buildSignatureDictBody()` | фиксированные 16384 hex = 8 КБ; цепочка + метка времени + OCSP не влезут |
| Существующие PDF | `ExistingFormFiller` | переписывает файл целиком → существующие подписи ломаются; дописывания в конец файла (incremental update) нет |
| Reader | `XrefTable` | не отдаёт `startxref` последней секции и тип xref (таблица/поток) — нужны для `/Prev` |

ГОСТ Р 34.10-2012 уже работает через OpenSSL gost-engine (openssl сам разбирает ключи), отдельный код под ГОСТ сейчас не нужен; после этапа B понадобятся OID-ы алгоритмов.

## 2. Целевая архитектура

Новое пространство имён `Dskripchenko\PhpPdf\Pdf\Signature\`:

```
Signature/
  Appearance/      SignatureAppearance, AppearanceRenderer, пресеты, CertificateInfo
  Asn1/            DerWriter, DerReader, Oid — минимальный ASN.1 без зависимостей
  Cms/             SignedDataBuilder, SignerInfo, Attributes (ESS, timestamp), CmsParser
  Signer/          Signer (интерфейс), OpenSslSigner, Algorithm (RSA/ECDSA/GOST)
  Tsa/             TsaClient (RFC 3161), TimeStampToken
  Revocation/      OcspClient, CrlFetcher, RevocationData
  Transport/       HttpTransport (интерфейс) + StreamHttpTransport (без зависимостей)
  Incremental/     IncrementalWriter, RevisionInfo
  PdfSigner.php    фасад: sign / prepare / complete / timestamp / addLtv
  Validation/      SignatureValidator, ValidationReport
```

Принципы:
- **Без новых обязательных зависимостей** в composer: `ext-openssl` остаётся в `suggest`, HTTP — через интерфейс со встроенной реализацией на stream-контексте.
- **Обратная совместимость**: `SignatureConfig` и `Document::sign()` продолжают работать до 2.0 и внутри переходят на новый движок (этап B). Помечаются `@deprecated` с указанием замены.
- Каждый этап — отдельная ветка + PR + зелёный CI + минорный релиз.
- Документация en/ru/de/zh (`docs/*/SIGNING.md` — новый файл, ссылка из USAGE), CHANGELOG на английском.

---

## Этап A — видимый штамп подписи · релиз 1.11.0

Цель: внешний вид поля подписи настраивается, есть готовый «штамп ЭП», поле ставится там, где задано в документе.

### A1. Модель внешнего вида
- `SignatureAppearance` (readonly value object): размер, рамка (толщина/цвет/скругление), фон, список слоёв:
  - `TextBlock` — строки с подстановками `{cn}`, `{org}`, `{serial}`, `{validFrom}`, `{validTo}`, `{issuer}`, `{signedAt}`, `{reason}`, `{location}`, `{inn}`, `{ogrn}`, `{snils}`; шрифт, кегль, цвет, выравнивание, автоуменьшение кегля под размер блока;
  - `ImageBlock` — PNG/JPEG (скан подписи, печать), с прозрачностью (alpha уже поддерживается в `Image`);
  - `QrBlock` — QR со ссылкой (на проверку и т.п.) через существующий `QrEncoder`;
  - позиции слоёв — в долях или pt от рамки.
- `CertificateInfo::fromPem()` — разбор через `openssl_x509_parse`: CN, O, серийный (hex), срок действия, издатель; российские OID в subject: ИНН `1.2.643.3.131.1.1`, ИНН ЮЛ `1.2.643.100.4`, ОГРН `1.2.643.100.1`, ОГРНИП `1.2.643.100.5`, СНИЛС `1.2.643.100.3`.
- Пресеты:
  - `SignatureAppearance::electronicSignatureStamp()` — привычный вид отметки об ЭП: синяя рамка, заголовок «ДОКУМЕНТ ПОДПИСАН ЭЛЕКТРОННОЙ ПОДПИСЬЮ», строки «Сертификат / Владелец / Действителен с … по …»; подписи строк локализуемы (ru/en);
  - `::simple()` — «Digitally signed by {cn}, {signedAt}»;
  - `::imageOnly()` — только картинка.

### A2. Рендер внешнего вида
- `AppearanceRenderer` строит Form XObject `/AP /N`, текст — встроенным TTF-подмножеством того же резолвера шрифтов, что у документа (кириллица; PDF/A требует встраивания шрифтов в /AP).
- Сертификат известен до записи файла → подстановки делаются до подписания. Если сертификата заранее нет (C, режим «CMS целиком снаружи»), поля сертификата обязаны прийти в запросе явно или пропускаются.
- Виджет: `/F 4` (Print), `/P` на страницу, `/MK`; для невидимой подписи — `/Rect [0 0 0 0]`.

### A3. Размещение
- Новый элемент потока `Element\SignatureField(name, widthPt, heightPt, appearance?, …)` — ставится в любом месте `Section`/таблицы/колонки; `FormField(type: signature)` остаётся как совместимый вариант.
- Низкоуровнево: `Page::addSignatureField(name, x, y, w, h, appearance)`.
- Несколько полей в документе; `SignatureConfig`/запрос подписи указывает поле по имени (`fieldName`), остальные остаются пустыми — для следующих подписантов (этап D).
- «Штамп на каждой странице»: основное поле — один виджет; на остальных страницах — неинтерактивные копии того же Form XObject в содержимом страницы. Причина: поле подписи с несколькими виджетами допускается спецификацией, но валидаторы и просмотрщики ведут себя по-разному. Вариант с несколькими виджетами — опцией, выключенной по умолчанию.

### A — что сделано и чем отличается от плана

- Решения 1–2 из раздела 5 приняты как предложено: канонический пресет, копии штампа — неинтерактивным содержимым (`/Artifact`).
- Штамп рисуется оператором-строкой и делит `/Resources` страницы: шрифты и картинки регистрируются на странице до эмиссии ресурсов (`Pdf\Document::prepareSignatureFields()`).
- Шрифт текста: `SignatureAppearance::$font` → шрифт документа (в Engine) → Helvetica только для WinAnsi; под PDF/A Helvetica запрещена. Жирный без жирного шрифта — обводкой (`2 Tr`).
- Попутно: `/F 4` у виджета, `NeedAppearances` не пишется для подписанных и PDF/A, время подписи фиксируется один раз на запись; исправлен `toFile()` подписанного документа (файл открывался только на запись).
- Тестовый сертификат с фиксированным серийным — `tests/fixtures/pki/`.
- Известное ограничение A: `openssl_pkcs7_sign` пишет в CMS свой `signingTime` (текущее время), поэтому при явном `signedAt` в прошлом валидаторы (pyHanko) показывают время сборки, а `/M` и штамп — `signedAt`. Закрывается в B3 (PAdES без `signingTime` в CMS; в режиме совместимости — `signingTime` = `signedAt`).
- Проверено вне CI: Preview (вид), Ghostscript `-dPrinted` (печать), pyHanko — VALID, покрывает весь файл.
- Не сделано в A: ручная матрица просмотрщиков (Acrobat, Foxit, Preview) — за автором; golden-PNG штампа берётся из артефакта CI.

### A4. Проверки и тесты
- Визуальные эталоны (golden) для каждого пресета, включая кириллицу и QR.
- veraPDF: подписанный PDF/A-2b с видимым штампом проходит.
- Ручная матрица в `VIEWER-MATRIX.md`: Acrobat Reader, Foxit, Chrome, Firefox (pdf.js), macOS Preview — штамп отображается и печатается.

---

## Этап B — правильный CMS и PAdES · релиз 1.12.0

Цель: подпись опознаётся строгими валидаторами как PAdES (`ETSI.CAdES.detached`) с меткой времени; RSA, ECDSA и ГОСТ одним путём.

### B1. ASN.1
- `DerWriter` (SEQUENCE/SET/INTEGER/OID/OCTET/BIT/UTCTime/GeneralizedTime/контекстные теги, сортировка SET OF по DER) и `DerReader` (дерево с сохранением исходных байтов узла — нужно, чтобы брать issuer `Name` из сертификата побайтно).
- Отдельные модульные тесты против выдачи `openssl asn1parse`.

### B2. Подписант
- Интерфейс `Signer`: `certificate()`, `chain()`, `algorithm()`, `sign(string $data): string` (сырая подпись над DER signedAttrs).
- `OpenSslSigner` (PEM/PKCS#12 + пароль) — `openssl_sign` с нужным digest.
- `Algorithm`: RSA PKCS#1 v1.5 (SHA-256/384/512), RSA-PSS (опционально), ECDSA P-256/384, ГОСТ Р 34.10-2012 256/512 со Стрибог 256/512 (OID `1.2.643.7.1.1.1.1/2`, `1.2.643.7.1.1.2.2/3`). Digest выводится из ключа, переопределяется явно.
- Этот же интерфейс — точка расширения для синхронных HSM/PKCS#11, которые пользователь подключает сам.

### B3. CMS SignedData
- version 1, digestAlgorithms, encapContentInfo `id-data` без содержимого (detached), certificates = подписант + цепочка, signerInfo с `IssuerAndSerialNumber`.
- signedAttrs: `contentType`, `messageDigest`, `signingCertificateV2` (RFC 5035); в режиме PAdES **без** `signingTime` (время — в `/M`).
- Режимы `/SubFilter`: `ETSI.CAdES.detached` (по умолчанию) и `adbe.pkcs7.detached` (совместимость; со `signingTime`).
- Размер `/Contents` вычисляется: DER сертификатов + оценка подписи + резерв под метку времени + запас; переопределяется `contentsReserveBytes`. Переполнение — понятное исключение с нужным размером.
- `Writer::applyPkcs7Signature` заменяется вызовом нового билдера; `openssl_pkcs7_sign` и временные файлы уходят.

### B4. Метка времени (PAdES-B-T)
- `TsaClient`: RFC 3161 `TimeStampReq` (DER, nonce, certReq), POST `application/timestamp-query`, разбор `TimeStampResp`, проверка статуса, imprint и nonce. Basic/bearer-аутентификация, таймауты.
- Токен кладётся неподписанным атрибутом `id-aa-signatureTimeStampToken` над значением подписи.
- `HttpTransport` — интерфейс; `StreamHttpTransport` по умолчанию; в тестах — подставной.

### B5. Профили
- `SignatureProfile::B_B`, `B_T` в этом этапе. `B_LT` / `B_LTA` требуют дописывания в конец файла (DSS + document timestamp) → этап D.

### B — что сделано и чем отличается от плана

- CMS собирается своим кодом (`Signature\Asn1`, `Signature\Cms\CmsBuilder`); `openssl_pkcs7_sign` и временные файлы ушли. По умолчанию `ETSI.CAdES.detached`, `adbe.pkcs7.detached` — опцией; в нём `signing-time` = `signedAt` (ограничение A закрыто).
- `Signer` + `OpenSslSigner` (PEM, PKCS#12, цепочка). `CmsBuilder` уже разделён на `signedAttributes()` / `assemble()` — основа для этапа C.
- Алгоритмы: RSA PKCS#1 v1.5, ECDSA P-256/384/521, ГОСТ 2012-256/512. **RSA-PSS не делаем**: `openssl_sign` в PHP не умеет PSS-паддинг; при необходимости — через свой `Signer`.
- `SignatureProfile` (B5) не заведён: уровень следует из конфигурации — без TSA B-B, с TSA B-T.
- ГОСТ: OID-ы сверены с `openssl cms -sign` (signatureAlgorithm = ключевой OID 1.2.643.7.1.1.1.x); проверка `openssl cms -verify` в образе `docker/gost`, отдельный CI-job. Проверено в КриптоПро CSP (cptools, macOS) 2026-10-13: отделённая подпись нашего `CmsBuilder` тестовым ГОСТ 2012-256 ключом — «успешно проверена», тип CAdES-BES, OID-ы распознаны. PDF под КриптоПро PDF (Acrobat/Windows) не проверялся.
- CI: pyHanko (`pipx install pyhanko-cli`) в job PHP 8.4. EU DSS — не подключён (решение 4 открыто).

### B6. Тесты и CI
- Тестовый УЦ в `tests/fixtures/pki/`: корень → промежуточный → конечный сертификат для RSA, ECDSA и ГОСТ; локальный тестовый TSA (ответы собираются в тестах, сеть не нужна).
- CI: `openssl cms -verify` по `/Contents`; **pyHanko** (`pyhanko sign validate`) — независимая проверка PAdES-уровня; отдельный job с образом OpenSSL+gost-engine для ГОСТ-подписи.
- Опционально (ручной/ночной): EU DSS validation (Java) — отчёт уровня B-B/B-T.
- Ручная проверка: Acrobat — «подпись действительна» (с доверием к тестовому корню), КриптоПро PDF — ГОСТ-подпись.

---

## Этап C — внешняя (двухфазная) подпись · релиз 1.13.0

Цель: подписать PDF ключом, которого нет на сервере — КриптоПро Browser plug-in, токен, облачная подпись, HSM с подтверждением.

### C1. API
```php
$prepared = PdfSigner::prepare($pdfOrDocument, new SignatureRequest(
    fieldName: 'sig1',
    certificatePem: $cert,          // если известен заранее — для штампа и ESS
    appearance: SignatureAppearance::electronicSignatureStamp(),
    mode: ExternalMode::RawSignature, // или ExternalMode::Cms
    profile: SignatureProfile::B_T,
));
$prepared->dataToSign();    // что отдать подписанту (хеш signedAttrs или хеш документа)
$prepared->digestAlgorithm();
$state = $prepared->serialize(); // сохранить между запросами

$signed = PdfSigner::complete(PreparedSignature::unserialize($state), $externalResult);
```

### C2. Режимы
- **RawSignature** — signedAttrs собираем мы (нужен сертификат заранее), снаружи подписывается их хеш; на выходе — полноценный PAdES с ESS.
- **Cms** — снаружи приходит готовый CMS (типично `cadesplugin` `SignHash` → CAdES-BES detached над хешем документа). Мы проверяем: `messageDigest` совпадает с хешем ByteRange, подпись над signedAttrs корректна, размер влезает в `/Contents`. При профиле B-T дописываем метку времени в unsignedAttrs (разбор и пересборка SignerInfo через `DerReader`).

### C3. Состояние между фазами
- Подготовленные байты PDF хранятся в `PreparedSignature` (сериализуемо; хранение — забота вызывающего). Повторная генерация документа не используется: она требовала бы полной детерминированности (`/ID`, `/M`, порядок объектов).
- Срок жизни подготовки, защита от подмены: в состоянии — хеш подготовленных байтов, `complete()` его сверяет.

### C4. Тесты и документация
- Эмуляция внешнего подписанта в тестах (OpenSslSigner за двухфазной границей) для RSA и ГОСТ.
- Гайд «КриптоПро Browser plug-in»: последовательность вызовов на стороне браузера (`CadesSignHash` / `CreateObjectAsync`), формат передачи хеша (hex/base64, порядок байтов ГОСТ). JS-код — только пример в документации, в пакет не входит.

---

## Этап D — дописывание в конец файла, несколько подписей, LTV, проверка · релизы 1.14.0 (D1–D4) и 1.15.0 (D5–D6)

### D1. IncrementalWriter
- Поверх `ReaderDocument`: исходные байты не трогаются, изменённые и новые объекты дописываются в конец, новая xref-секция, trailer с `/Prev`, `/Size`, `/Root`, `/Info`, `/ID` (первый элемент сохраняется, второй новый).
- Тип секции следует последней секции оригинала: таблица → таблица, поток → xref-поток (гибридные файлы — таблица + `/XRefStm`).
- Reader: `XrefTable`/`XrefReader` начинают отдавать `startxref` последней секции, её тип и максимальный номер объекта.
- Зашифрованный вход: в D1 — явный отказ; шифрование дописываемых объектов тем же ключом — отдельной задачей (D6).

### D2. Подпись существующих PDF
- Подписать готовый PDF: создать поле + виджет (страница переписывается новой ревизией объекта со своим `/Annots`), создать/обновить `/AcroForm`, `/SigFlags 3`.
- Подписать существующее пустое поле подписи (в т.ч. созданное на этапе A).
- Последовательные подписи нескольких подписантов: каждая — своя ревизия; предыдущие остаются действительными.
- Работает для всех режимов B и C.

### D3. Права на изменения
- Сертифицирующая подпись: `/Reference` с `/TransformMethod /DocMDP`, `/P 1|2|3`, `/Perms /DocMDP` в каталоге.
- `/Lock` (FieldMDP) у поля — заблокировать поля после подписи.
- `ExistingFormFiller`: режим `incremental: true` — заполнение формы без поломки подписей, с проверкой разрешений DocMDP; режим полной перезаписи остаётся по умолчанию с явным предупреждением в доке.

### D4. Долгосрочная проверка (PAdES-B-LT / B-LTA)
- `OcspClient` (запрос/ответ по AIA сертификата) и `CrlFetcher` (CDP); `RevocationData`.
- DSS-словарь в каталоге: `/Certs`, `/OCSPs`, `/CRLs`, опционально `/VRI` — добавляется отдельной ревизией после подписи.
- Метка времени документа: `/Type /DocTimeStamp`, `/SubFilter /ETSI.RFC3161` → B-LTA; повторное «продление» архива.
- `PdfSigner::addLtv()` / `timestamp()` — отдельные операции над уже подписанным файлом.

### D5. Проверка подписей
- `SignatureValidator::validate($pdf, TrustStore)` → `ValidationReport` по каждой подписи:
  - ByteRange начинается с 0, разрыв совпадает ровно с `/Contents`, покрытие до конца своей ревизии;
  - digest и подпись CMS, ESS-атрибут против сертификата подписанта;
  - цепочка до доверенного корня, срок действия на момент подписи (по метке времени, если есть), отзыв (из DSS/OCSP, если есть);
  - что изменилось после подписи: перечень ревизий и затронутых объектов; оценка «допустимо по DocMDP / нет» — в упрощённом виде (формы, аннотации, подписи — допустимо; содержимое страниц — нет).
- Защита от известных атак на подписанный PDF (Universal Signature Forgery, Incremental Saving Attack, Signature Wrapping, shadow-атаки): тестовые файлы из публичных наборов в `tests/fixtures/attacks/`.
- `extractSignedRevision()` — байты документа ровно в том виде, в каком он был подписан.

### D6. Остатки
- Дописывание в зашифрованные документы.
- Подпись документа, который сам генерируется с xref-потоками и object streams (сейчас при подписи они отключаются): после D1 можно писать основное тело компактно, а подпись — ревизией сверху.

---

## 3. Сквозные задачи

- **Документация**: `docs/{en,ru,de,zh}/SIGNING.md` — штамп, PAdES-профили, ГОСТ, внешняя подпись (КриптоПро), несколько подписантов, проверка; обновить `COMPARISON.md` (сравнение с TCPDF/SetaPDF-Signer), `CONFORMANCE.md`.
- **Безопасность**: пароли и ключи не попадают в исключения и логи; временные файлы не используются (после B); `HttpTransport` по умолчанию проверяет TLS.
- **phpstan** без новых записей в baseline; бенчмарк подписи (время, размер файла) в `scripts/bench`.

## 4. Риски

| Риск | Что делаем |
|---|---|
| Формат ГОСТ-подписи в CMS (порядок байтов r/s у gost-engine против ожиданий КриптоПро) | ранняя проверка в B2 на реальном ГОСТ-сертификате: `openssl cms -verify` + КриптоПро PDF; при расхождении — нормализация в `Algorithm` |
| Ответ КриптоПро plug-in отличается от ожидаемого CAdES-BES (атрибуты, кодировки) | в C — тестовые CMS, снятые с реального плагина, в fixtures |
| Атаки на инкрементальные обновления | D5 с публичными наборами атакующих PDF |
| Размер `/Contents` при длинных цепочках и CRL | вычисляемый резерв + понятная ошибка; CRL уходят в DSS, а не в CMS |
| Строгие валидаторы (EU DSS) находят несоответствия профилю | pyHanko в CI с этапа B, EU DSS — ночной job |

## 5. Решения, которые нужно подтвердить

1. Текст и вид пресета «штамп ЭП» — канонический вариант (как выше) или есть свой образец.
2. Копии штампа на каждой странице — неинтерактивными картинками (предложено) или несколькими виджетами одного поля.
3. Нужен ли RSA-PSS и ECDSA P-384 в B или достаточно RSA PKCS#1 + ECDSA P-256 + ГОСТ.
4. EU DSS в CI — обязательный job или ночной/ручной.
