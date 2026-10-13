# Цифровая подпись

php-pdf подписывает документ в момент записи: отсоединённая подпись PKCS#7
(`adbe.pkcs7.detached`) через расширение `openssl`, а плейсхолдеры
`/ByteRange` и `/Contents` патчатся после сборки файла. Поле подписи может
показывать **штамп подписи**: рамку с текстом, картинками и QR-кодом,
заполняемую данными сертификата в момент подписания документа.

- [Подпись](#подпись)
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
