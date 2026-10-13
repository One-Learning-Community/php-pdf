<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Signature;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\DerReader;
use Dskripchenko\PhpPdf\Pdf\Signature\Asn1\Oid;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalMode;
use Dskripchenko\PhpPdf\Pdf\Signature\External\ExternalSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GOST R 34.10-2012 signing. Runs where OpenSSL's gost engine is loaded into
 * PHP and the CLI — in CI, the docker/gost image; skipped elsewhere.
 */
final class GostSignatureTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        if (! function_exists('openssl_get_md_methods') || ! in_array('md_gost12_256', openssl_get_md_methods(), true)) {
            return;
        }
        self::$dir = sys_get_temp_dir().'/phppdf-gost-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
        foreach (['256', '512'] as $bits) {
            exec(sprintf(
                'openssl req -x509 -newkey gost2012_%1$s -pkeyopt paramset:A -nodes -keyout %2$s/key%1$s.pem -out %2$s/cert%1$s.pem '
                .'-subj "/C=RU/O=php-pdf test/CN=ГОСТ подписант %1$s" -days 30 -md_gost12_%1$s -utf8 2>&1',
                $bits,
                escapeshellarg(self::$dir),
            ), $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("Cannot make a GOST $bits certificate:\n".implode("\n", $out));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== '') {
            array_map('unlink', (array) glob(self::$dir.'/*'));
            rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        if (self::$dir === '') {
            self::markTestSkipped('OpenSSL gost engine not loaded (see docker/gost)');
        }
    }

    private static function signer(string $bits): OpenSslSigner
    {
        return new OpenSslSigner(
            (string) file_get_contents(self::$dir."/cert$bits.pem"),
            (string) file_get_contents(self::$dir."/key$bits.pem"),
        );
    }

    /** @return iterable<string, array{string, KeyType, DigestAlgorithm, string, string}> */
    public static function variants(): iterable
    {
        yield '2012-256' => ['256', KeyType::Gost256, DigestAlgorithm::Gost256, Oid::GOST_3411_2012_256, Oid::GOST_3410_2012_256];
        yield '2012-512' => ['512', KeyType::Gost512, DigestAlgorithm::Gost512, Oid::GOST_3411_2012_512, Oid::GOST_3410_2012_512];
    }

    #[Test]
    #[DataProvider('variants')]
    public function a_gost_signed_pdf_verifies(string $bits, KeyType $type, DigestAlgorithm $digest, string $digestOid, string $signatureOid): void
    {
        $signer = self::signer($bits);
        self::assertSame($type, $signer->certificate()->keyType());
        self::assertSame($digest, $signer->digestAlgorithm());

        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60);
        $doc->sign(new SignatureConfig(signer: $signer));
        $pdf = $doc->toBytes();

        [$der, $content] = Pki::extract($pdf);
        $dir = self::$dir;
        file_put_contents("$dir/sig.der", $der);
        file_put_contents("$dir/data", $content);
        exec(sprintf(
            'openssl cms -verify -binary -inform DER -in %s -content %s -CAfile %s -out /dev/null 2>&1',
            escapeshellarg("$dir/sig.der"),
            escapeshellarg("$dir/data"),
            escapeshellarg("$dir/cert$bits.pem"),
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        // The identifiers OpenSSL's own `cms -sign` writes for GOST.
        $signerInfo = DerReader::parse($der)->child(1)->child(0)->child(4)->child(0);
        self::assertSame($digestOid, $signerInfo->child(2)->child(0)->oid());
        self::assertSame($signatureOid, $signerInfo->child(4)->child(0)->oid());

        // ESSCertIDv2 names the Streebog hash it uses.
        foreach ($signerInfo->contextChild(0)?->children ?? [] as $attr) {
            if ($attr->child(0)->oid() === Oid::SIGNING_CERTIFICATE_V2) {
                $certId = $attr->child(1)->child(0)->child(0)->child(0);
                self::assertSame($digestOid, $certId->child(0)->child(0)->oid());
                self::assertSame($digest->digest($signer->certificate()->der), $certId->child(1)->content);
            }
        }
    }

    #[Test]
    public function the_stamp_reads_a_gost_certificate(): void
    {
        $font = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';
        if (! is_readable($font)) {
            self::markTestSkipped('Liberation fonts not fetched');
        }
        $regular = new \Dskripchenko\PhpPdf\Pdf\PdfFont(\Dskripchenko\PhpPdf\Font\Ttf\TtfFile::fromFile($font));
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 75, SignatureAppearance::electronicSignatureStamp('ru', $regular));
        $doc->sign(new SignatureConfig(signer: self::signer('256')));

        self::assertStringContainsString($regular->encodeText('Владелец: ГОСТ подписант 256'), $doc->toBytes());
    }

    #[Test]
    public function an_outside_gost_cms_completes_the_document(): void
    {
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60);
        $prepared = $doc->prepareSignature(new SignatureConfig(
            signer: new ExternalSigner((string) file_get_contents(self::$dir.'/cert256.pem')),
        ));
        self::assertSame(DigestAlgorithm::Gost256, $prepared->digest);

        $dir = self::$dir;
        file_put_contents("$dir/content", $prepared->signedContent());
        exec(sprintf(
            'openssl cms -sign -binary -md md_gost12_256 -in %s -signer %s -inkey %s -outform DER -out %s 2>&1',
            escapeshellarg("$dir/content"), escapeshellarg("$dir/cert256.pem"),
            escapeshellarg("$dir/key256.pem"), escapeshellarg("$dir/ext.cms"),
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        $pdf = $prepared->complete((string) file_get_contents("$dir/ext.cms"));
        [$der, $content] = Pki::extract($pdf);
        file_put_contents("$dir/sig.der", $der);
        file_put_contents("$dir/data", $content);
        exec(sprintf(
            'openssl cms -verify -binary -inform DER -in %s -content %s -CAfile %s -out /dev/null 2>&1',
            escapeshellarg("$dir/sig.der"), escapeshellarg("$dir/data"), escapeshellarg("$dir/cert256.pem"),
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    #[Test]
    public function a_little_endian_gost_raw_signature_is_accepted(): void
    {
        $key = self::signer('256');
        $doc = Document::new(compressStreams: false);
        $doc->addPage()->addSignatureField('sig', 72, 600, 250, 60);
        $prepared = $doc->prepareSignature(
            new SignatureConfig(signer: new ExternalSigner((string) file_get_contents(self::$dir.'/cert256.pem'))),
            ExternalMode::RawSignature,
        );

        // CryptoAPI hands GOST values back byte-reversed.
        $pdf = $prepared->complete(strrev($key->sign($prepared->dataToSign())));
        [$der, $content] = Pki::extract($pdf);
        $dir = self::$dir;
        file_put_contents("$dir/sig.der", $der);
        file_put_contents("$dir/data", $content);
        exec(sprintf(
            'openssl cms -verify -binary -inform DER -in %s -content %s -CAfile %s -out /dev/null 2>&1',
            escapeshellarg("$dir/sig.der"), escapeshellarg("$dir/data"), escapeshellarg("$dir/cert256.pem"),
        ), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
