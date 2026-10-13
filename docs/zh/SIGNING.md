# 数字签名

php-pdf 在写出文档的同时完成签名：通过 `openssl` 扩展生成 PKCS#7 分离签名
（`adbe.pkcs7.detached`），文件组装完成后再回填 `/ByteRange` 与 `/Contents`
占位符。签名字段可以显示**签章**：一个包含文字、图片和二维码的边框，
在文档签名时根据证书内容自动填充。

- [签名](#签名)
- [签名字段](#签名字段)
- [签章](#签章)
- [现成签章](#现成签章)
- [占位符](#占位符)
- [字体](#字体)
- [多个签名字段](#多个签名字段)
- [每页签章](#每页签章)
- [PDF/A](#pdfa)

## 签名

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

`signedAt` 用于指定签名时间，默认为写出文档的时间。同一时刻会同时写入签名字典
（`/M`）和签章。

## 签名字段

在版式文档中，签名字段与图片一样，是流中的一个块：

```php
use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\SignatureField;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Alignment;

$doc = new Document(new Section([
    // …… 文档正文 ……
    new SignatureField('approval',
        widthPt: 250, heightPt: 75,
        appearance: SignatureAppearance::electronicSignatureStamp('en'),
        alignment: Alignment::End),
]), signature: new SignatureConfig($certPem, $keyPem));
```

在手工绘制的页面上，使用 `Page::addSignatureField()` 并给出绝对坐标。尺寸为零的字段
（`width: 0, height: 0`）是不可见签名。没有指定外观的字段是一个细的空边框。

`FormField(type: 'signature')` 和 `Page::addFormField('signature', …)`
仍然可用，它们绘制的是空边框。

## 签章

`SignatureAppearance` 是一个边框（边框颜色与宽度、圆角、背景、内边距），
内部按顺序绘制各个块：

| 块 | 绘制内容 |
|---|---|
| `TextBlock` | 带占位符的文本行；按空格折行，并缩小至 `minSizePt` 以适应空间，超出部分被裁剪；支持对齐、垂直对齐、颜色和粗体 |
| `ImageBlock` | PNG 或 JPEG —— 手写签名扫描件、印章、徽标；保持比例缩放适配；支持 PNG 透明度和 `opacity` |
| `QrBlock` | 二维码，例如指向验证页面的链接；数据中可使用占位符 |

块的位置由 `Box` 指定 —— 取内部区域的比例值，原点在左上角：

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

`withFonts()`、`withVariables()` 和 `withBlocks()` 返回修改后的副本。

## 现成签章

| 预设 | 外观 |
|---|---|
| `SignatureAppearance::electronicSignatureStamp('ru' \| 'en', $font, $boldFont, $color, $emblem)` | 常见的电子签名标记：彩色圆角边框，标题为 "DOCUMENT SIGNED WITH AN ELECTRONIC SIGNATURE"（文档已用电子签名签署），其后依次为证书序列号、持有人和有效期；左侧可选徽标；约 250 × 75 pt |
| `SignatureAppearance::simple($font, $color)` | "Digitally signed by …"，随后是日期，以及已设置时的签署原因和地点 |
| `SignatureAppearance::imageOnly($image, $borderColor)` | 仅显示一张图片 |

## 占位符

| 占位符 | 值 |
|---|---|
| `{cn}`、`{org}`、`{email}` | 证书主体的 CN、O 和电子邮箱 |
| `{serial}` | 证书序列号，大写十六进制 |
| `{validFrom}`、`{validTo}` | 证书有效期，格式由 `dateFormat` 决定 |
| `{issuer}` | 颁发者的 CN |
| `{inn}`、`{innle}`、`{ogrn}`、`{ogrnip}`、`{snils}` | 俄罗斯合格证书的标识符 |
| `{signer}` | `SignatureConfig::$signerName`，否则为证书的 CN |
| `{signedAt}` | 签名时间，格式由 `dateTimeFormat` 决定 |
| `{reason}`、`{location}`、`{contact}` | 来自 `SignatureConfig` |
| `variables` 的任意键 | 自定义值 |

如果某一行中的占位符全部为空，则该行会被丢弃，因此对于没有 `{inn}` 的证书，
`'INN: {inn}'` 这一行不会出现。

## 字体

签章文字需要包含所用字形的字体：

1. 若已设置，使用 `SignatureAppearance::$font` / `$boldFont`；
2. 在版式文档内 —— 使用文档的默认字体和粗体字体
   （`Engine` 的 `defaultFont` / `boldFont`）；
3. 否则使用 Helvetica，它只覆盖 WinAnsi（拉丁字符）。超出该范围的文字会直接抛出
   异常，而不是绘制成乱码。

没有粗体字体时，粗体文字通过描边字形轮廓来加粗。

## 多个签名字段

用 `SignatureConfig::$fieldName` 指定要签署的字段；未指定时签署第一个签名字段。
其余字段保持为空，留给后续签署人：它们的签章只使用各自的 `variables` 绘制，
因此可以显示 "Place for signature"（签名处）。字段名未知则报错。

## 每页签章

`repeatOnOtherPages: true` 还会在其他每一页的相同位置绘制签章，作为标记为
artifact 的普通内容。只有原始字段是可交互的 —— 查看器和验证器对同一签名字段的
多个控件处理方式不一，因此这些副本不是控件。

## PDF/A

带签章的已签名 PDF/A-2b 文档在每次推送时都会通过 veraPDF 验证。PDF/A 要求嵌入
所有字体，因此 PDF/A 下的签章必须使用嵌入字体（来自外观或文档）；此时 Helvetica
会被拒绝。已签名文档和 PDF/A 文档不会写入 `NeedAppearances`。
