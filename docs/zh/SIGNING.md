# 数字签名

php-pdf 在写出文档的同时完成签名：生成 **PAdES** 格式的分离 CMS 签名
（`ETSI.CAdES.detached`，ETSI EN 319 142），由库自身构建，并通过 `openssl`
扩展完成签名，文件组装完成后再回填 `/ByteRange` 与 `/Contents` 占位符。
支持 RSA、ECDSA 和 GOST R 34.10-2012 密钥；配合时间戳服务（TSA），签名可达到
PAdES B-T 级别。签名字段可以显示**签章**：一个包含文字、图片和二维码的边框，
在文档签名时根据证书内容自动填充。

- [签名](#签名)
- [密钥和证书](#密钥和证书)
- [签名格式](#签名格式)
- [时间戳](#时间戳)
- [GOST R 34.10-2012](#gost-r-3410-2012)
- [签名大小](#签名大小)
- [通过返回 CMS 的软件使用密钥](#通过返回-cms-的软件使用密钥)
- [在服务器之外签名](#在服务器之外签名)
- [签署已有 PDF](#签署已有-pdf)
- [认证签名与锁定字段](#认证签名与锁定字段)
- [长期验证](#长期验证)
- [填写已签名的表单](#填写已签名的表单)
- [加密的 PDF](#加密的-pdf)
- [验证签名](#验证签名)
- [检查签名](#检查签名)
- [分离式签名](#分离式签名)
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

## 密钥和证书

提供密钥的三种方式：

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

`Signer` 是一个小接口：`certificate()`、`chain()`、`digestAlgorithm()` 和
`sign(string $data): string`。库会把签名属性的 DER 交给 `sign()`，并期望返回原始签名值，
因此密钥无需存在于 PHP 中。

现成的 `Signer` 由配套包提供：
[php-pdf-pkcs11](https://github.com/dskripchenko/php-pdf-pkcs11) 通过 PKCS#11
使用 HSM、智能卡或 USB 令牌上的密钥签名（`Pkcs11Signer::open()`），
[php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) 则使用
AWS KMS（`AwsKmsSigner::create()`）、Google Cloud KMS（`GoogleKmsSigner`）或
Azure Key Vault（`AzureKeyVaultSigner::fromCertificate()`）中的密钥签名：

```php
use Dskripchenko\PhpPdfCloudKms\Aws\AwsKmsSigner;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;

$signer = Pkcs11Signer::open('/usr/lib/softhsm/libsofthsm2.so', pin: $pin, keyLabel: 'signing-key');
$signer = AwsKmsSigner::create('alias/pdf-signing', $certificatePem, region: 'eu-central-1');
```

摘要算法随密钥而定：RSA 和 P-256 使用 SHA-256，P-384 使用 SHA-384，P-521 使用
SHA-512，GOST 使用 Streebog。向 `OpenSslSigner` 传入 `digest:` 可选择其他算法。

## 签名格式

| `subFilter` | 说明 |
|---|---|
| `SubFilter::CadesDetached`（默认） | PAdES：CAdES 分离 CMS，通过 `signing-certificate-v2` 绑定证书；签名时间为 `/M`，而不是 CMS 属性。PAdES B-B，带时间戳时为 B-T |
| `SubFilter::Pkcs7Detached` | 较旧的 `adbe.pkcs7.detached`；CMS 中带有 `signing-time`，与 `/M` 设置为同一时刻 |

两者都是分离的 CMS `SignedData`，内嵌签署人证书和证书链。除非接收方的软件早于 PAdES，
否则请保持默认值。

## 时间戳

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Tsa\TsaClient;

new SignatureConfig(
    signer: $signer,
    timestampAuthority: new TsaClient('https://freetsa.org/tsr'),
);
```

签名值会发送给 RFC 3161 时间戳服务（TSA），令牌作为 `signature-time-stamp`
属性写入签名：此时签名时间由 TSA 担保，而不是依赖签署人的时钟（PAdES B-T）。
`TsaClient` 接受摘要算法、HTTP 基本认证凭据、额外请求头（API 密钥）、策略 OID
和超时时间。它会检查应答 —— 已授予状态、摘要值（imprint）以及所发送的 nonce ——
否则抛出 `TsaException`。

请求通过 PHP 的流包装器发出，并启用 TLS 验证。若要使用自己的 HTTP 客户端、代理或
重试策略，请实现 `Transport\HttpTransport` 并通过 `transport:` 传入。

## GOST R 34.10-2012

GOST 密钥（2012，256 位和 512 位）的签名方式与 RSA 相同，使用 Streebog 摘要以及
OpenSSL 和 CryptoPro 所用的标识符。PHP 必须加载 OpenSSL 的
[gost engine](https://github.com/gost-engine/engine) —— 它没有为 OpenSSL 3 打包，
需要从源码构建，并在 `openssl.cnf` 中启用。本仓库的 `docker/gost/` 是一份可用的
配方（CI 在其中运行 GOST 测试）。没有该引擎时无法读取 GOST 密钥，错误信息会说明这一点。

## 签名大小

`/Contents` 中的签名空间在签名生成之前就已预留。库根据证书、密钥大小以及是否请求
时间戳来估算所需空间。如果签署人嵌入的内容超出预期，签名会失败，并给出所需大小的提示；
可设置 `contentsReserveBytes` 来覆盖估算值。

## 通过返回 CMS 的软件使用密钥

有些密钥只能通过返回完整 CMS（而不是单纯签名值）的软件使用：服务器上的
CryptoPro CSP（`CadesSignedData.SignHash`、`cryptcp`）、签名服务、HSM 前端。
实现 `CmsSigner`，即可像使用进程内密钥一样一次调用完成签名，无需手动
prepare 和 complete。

对于服务器上的 CryptoPro CSP，
[php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)
提供了 `CryptoProCadesSigner` —— 一个基于 CryptoPro PHP 扩展的现成
`CmsSigner`：

```php
use Dskripchenko\PhpPdfCryptoPro\CryptoProCadesSigner;

$doc->sign(new SignatureConfig(signer: CryptoProCadesSigner::fromThumbprint('3A 5F … 9C', pin: $pin)));
```

只有在使用其他工具（`cryptcp`、签名服务）时才需要自己实现 `CmsSigner`：

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
        // 调用签名工具或 CSP API，返回 CMS
    }
}

$doc->sign(new SignatureConfig(signer: new CryptcpSigner($certPem, $thumbprint)));
```

`signCms()` 接收签名覆盖的字节及其按 `digestAlgorithm()` 计算的哈希，返回
DER 或 BER 编码的 CMS SignedData，可分离也可附加。嵌入前库会像
`complete()`（见下文）一样检查：它恰好覆盖本文档、由 `certificate()` 签发、
签名有效；缺少的证书从 `chain()` 补充，配置中的 `timestampAuthority` 会为其
加时间戳。证书会显示在印章上。适用于 `Pdf\Document::sign()`、排版文档的
`signature:` 以及 `PdfSigner::sign()`。

## 在服务器之外签名

服务器可以直接调用的 HSM 或云 KMS 不需要两步签名——请使用上文的 [PKCS#11 或 KMS 签名器](#密钥和证书)。两阶段签名适用于只有用户才能访问的密钥：令牌上或用户机器上 CryptoPro 中的合格证书（通过浏览器插件），或稍后才给出应答的签名服务。分两步签名：

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

| 模式 | 签署人获得 | 返回 |
|---|---|---|
| `ExternalMode::Cms`（默认） | `documentDigest()` —— 或 `signedContent()`，即字节本身，用于对文件签名的工具 | 分离的 CMS（CAdES-BES） |
| `ExternalMode::RawSignature` | `dataToSign()` —— 库构建的已签名属性 —— 或 `hashToSign()`，用于对哈希签名的设备 | 原始签名值 |

`complete()` 在嵌入之前会检查返回的内容：CMS 恰好覆盖这份文档（其消息摘要）；它是用传给 `prepareSignature()` 的证书（如果传了）制作的；其签名验证通过；并且能放入预留空间。CMS 缺少的证书会从准备阶段补入；传给 `complete()` 的时间戳机构会为签名加盖时间戳。CryptoAPI 小端序的 GOST 原始值会被识别并翻转。

请把状态保存在签署人无法修改的地方：它携带已准备好的文档，而其校验和只能发现损坏，无法发现篡改。准备之后，不得重新生成文档——签名覆盖的正是这些字节。

### CryptoPro Browser plug-in

在浏览器中，插件用用户的证书对哈希签名并返回 CMS——以下是基于 CryptoPro `cadesplugin` API 的示意：

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

请使用与密钥匹配的哈希算法：256 位 GOST 密钥用 `..._GOST_3411_2012_256`，512 位用 `..._GOST_3411_2012_512`。把证书传给 `ExternalSigner`（用 `cert.Export(CADESCOM_ENCODE_BASE64)` 从插件导出），这样摘要会随密钥而定，签章也会显示持有人；计算 Streebog 摘要时，服务器上同样需要 gost engine。以这种方式生成的 CMS 带有 `signing-time` 属性；验证器接受它，但严格的 PAdES 配置要求时间仅出现在 `/M` 中。

## 签署已有 PDF

`PdfSigner` 可以签署在别处生成的 PDF，或已经签过名的 PDF，方式是**增量更新**：
原有字节保持不变，签名写在其后，因此先前的签名仍然恰好覆盖它们当初所签的内容。
传统交叉引用表和交叉引用流两种形式都能处理。

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

被签署的字段是：给定了 `fieldName` 时即该字段（文档中已有的空签名字段，或用
`addField()` 添加的字段）；否则是用 `addField()` 添加的字段；再否则是第一个空签名字段；
最后才是第一页上新建的不可见字段。`signatureFields()` 列出文档的签名字段及其是否已签。
用 `prepare()` 代替 `sign()` 会得到 `PreparedSignature`，供服务器之外的密钥使用，
方式同上。加密的 PDF 使用其密码签署，参见[加密的 PDF](#加密的-pdf)。

## 认证签名与锁定字段

```php
use Dskripchenko\PhpPdf\Pdf\Signature\{DocMdpPermission, FieldLock};

new SignatureConfig(signer: $signer,
    certification: DocMdpPermission::FormFilling,   // what may change afterwards
    lockFields: FieldLock::only(['amount', 'date']), // fields frozen by this signature
);
```

**认证签名**（DocMDP）声明签名之后允许修改什么：`NoChanges`、`FormFilling`
（填写表单和签名）或 `FormFillingAndAnnotations`。只有文档的第一个签名可以对其认证。
认证之后，后续签名只能放入文档中已有的空签名字段——添加字段会改变页面，而认证禁止这样做——
因此请在认证更新中用 `PdfSigner::addEmptyField()`（或在生成文档时）布置好这些字段。
以 `NoChanges` 认证的文档不再接受后续签名。

`FieldLock::all()`、`only([...])` 或 `allExcept([...])` 用于锁定表单字段（FieldMDP）：
事后修改这些字段会使签名失效。

两者既适用于自己生成的文档（`Pdf\Document::sign()`），也适用于已有文档（`PdfSigner`）。

## 长期验证

多年之后要验证签名，验证器必须仍能找到证书及其吊销状态——OCSP 响应服务和 CRL
会随 CA 一同消失。PAdES 把它们保存在文档里：

```php
$bt   = $doc->toBytes();                       // B-T: signed with a timestamp
$blt  = PdfSigner::fromBytes($bt)->addValidationData();   // B-LT: + DSS
$blta = PdfSigner::fromBytes($blt)->timestamp($tsa);      // B-LTA: + document timestamp
```

`addValidationData()` 收集文档签名及其时间戳令牌的所有证书，下载缺失的颁发者证书
（AIA caIssuers——证书链省略的根证书），查询每个证书的 OCSP 响应服务，失败时回退到
其 CRL，并以增量更新的方式写入文档安全存储（DSS）。可以传入自己的 `RevocationSource`
（缓存、存储），或通过 `extra:` 传入要嵌入的数据；`fetchRevocation: false` 则只嵌入证书。
再次调用会扩充 DSS。

`timestamp()` 对整个文件（包括 DSS）添加文档时间戳（`ETSI.RFC3161`）。在最后一个
时间戳的证书到期之前，重复执行 `addValidationData()` + `timestamp()` 即可延长归档。

## 填写已签名的表单

`ExistingFormFiller::toBytes(incremental: true)` 把填写的值以增量更新的方式写入，
而不是重写整个文件，因此签名依然有效——为表单填写而认证的文档也依然保持认证状态：

```php
$filled = ExistingFormFiller::fromBytes($signedPdf)
    ->setValue('amount', '100')
    ->toBytes(incremental: true);
```

以 `NoChanges` 认证的文档会拒绝这种操作；在任何认证下，`flatten()` 和图片签章
（页面改动）也都会被拒绝。不带 `incremental: true` 时，填充器仍会重写整个文件，
这会使签名失效。

## 加密的 PDF

传入能打开该文件的密码：`PdfSigner::fromBytes($pdf,
$password)`、`ExistingFormFiller::fromBytes($pdf, $password)`、
`SignatureValidator::fromBytes($pdf, $password)`。更新内容使用文件自身的密钥加密
（RC4、AES-128、AES-256），签名值本身按格式要求保持明文，`/Encrypt` 和文档 ID
均予保留。只设置了所有者密码的文件用空密码即可打开。

## 验证签名

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\{SignatureValidator, TrustStore};

$report = SignatureValidator::fromBytes($pdf)
    ->validate(TrustStore::fromPem('root.crt'));   // or TrustStore::system()

$report->allValid();
foreach ($report->signatures as $s) {
    $s->fieldName; $s->isValid(); $s->errors; $s->warnings;
    $s->signer; $s->chain; $s->timestampTime; $s->revocation; $s->modifications;
}
$original = SignatureValidator::fromBytes($pdf)->signedRevision('approver');
```

对每个签名和文档时间戳：

| 检查 | 能发现什么 |
|---|---|
| `/ByteRange` 从 0 开始、不超出文件、其间隙恰好是 `<hex>` 签名值，被签名的字节止于某个修订版的 `%%EOF`，且该修订版的签名字典带有相同的值 | 签名包装（signature wrapping）、通用签名伪造（universal signature forgery）、被替换的 `/Contents` |
| 消息摘要与被签名字节一致；签名可用签名者的密钥验证；`signing-certificate-v2` 指向该证书 | 篡改、被替换的证书 |
| 签名时间戳覆盖此签名值、验证通过，且其 TSA 链至受信任的根——随后按该时间点评判证书链 | 倒填日期或被盗用的时间戳 |
| 签名者链至信任锚，每一环都用其颁发者的密钥验证，并在相应时间点有效 | 不受信任或已过期的签名者 |
| 来自 DSS 的 OCSP 响应和 CRL（其自身签名也会验证），或通过 `RevocationSource` 获取 | 已吊销的签名者——签名之前吊销为错误，带时间戳的签名之后吊销为警告 |
| 签名之后追加的一切内容，逐对象检查：签名（签名、时间戳、DSS）、表单填写、批注或其他任何内容——“其他任何内容”永不允许 | 增量保存攻击（incremental-saving attacks）、影子攻击（shadow attacks：被替换的页面内容、覆盖层） |
| 认证的 DocMDP 级别和签名的 FieldMDP 锁定与这些改动的对照 | 认证或锁定所禁止的改动 |

`modifications` 报告所达到的级别（`ModificationLevel::None`、`Signing`、
`FormFilling`、`Annotations`、`Other`）。批准签名之后跟有批注时仍然有效，但会
提示这一点；而在“表单填写”认证下，同样的批注会使其失效。

## 检查签名

测试套件使用独立于本库的工具检查每个签名：`openssl cms -verify`（验证至测试根 CA）、
用于时间戳的 `openssl ts -verify`，以及针对整个 PDF 的
[pyHanko](https://github.com/MatthiasValvekens/pyHanko)
（`pyhanko sign validate --trust root.crt file.pdf`），
包括多个签名者和认证签名的情形。长期验证使用 pyHanko 的 PAdES-LT / LTA 配置离线检查：
验证器只获得 TSA 的当前状态，因此签名者的吊销数据必须来自 DSS
（`scripts/conformance/pades-ltv-check.py`）。

## 分离式签名

文件旁的 `.p7s`：PDF 保持原样，签名是覆盖其字节的独立 CMS（任意字节，
不限于 PDF）：

```php
use Dskripchenko\PhpPdf\Pdf\Signature\Cms\DetachedSignature;

$p7s = DetachedSignature::sign($pdf, $signer, timestampAuthority: $tsa);   // DER
$report = DetachedSignature::verify($pdf, $p7s, TrustStore::fromPem('root.crt'));
$report->isValid(); $report->signer; $report->timestampTime;

$p7s = DetachedSignature::addTimestamp($cmsFromCryptoPro, $tsa);  // CAdES-BES → CAdES-T
```

`sign()` 生成 CAdES-BES：`content-type`、`message-digest`、`signing-time`
和 `signing-certificate-v2`，并嵌入签名者证书及证书链；配合时间戳服务即为
CAdES-T。`verify()` 返回与 PDF 验证相同的 `SignatureReport`：摘要与签名值、
证书绑定、时间戳、证书链（按时间戳时刻判断），以及在提供
`RevocationSource` 时的吊销状态。接受 DER、BER、base64 和 PEM；附加式签名
必须恰好携带所给内容；没有签名属性的签名（`openssl cms -noattr`）也可验证，
但会警告时间和证书均未与其绑定。

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
