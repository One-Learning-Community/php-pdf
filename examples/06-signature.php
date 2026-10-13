<?php

declare(strict_types=1);

// PKCS#7 detached signature with /ByteRange auto-patching and a visible
// signature stamp filled from the certificate. The demo key pair is generated
// on the fly — swap in your own PEM cert and key. Requires the openssl
// extension.

require __DIR__.'/bootstrap.php';

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Pdf\StandardFont;

if (! function_exists('openssl_pkey_new')) {
    echo "skipped: openssl extension not available\n";
    exit(0);
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'php-pdf example signer'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
$certPem = '';
$keyPem = '';
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem);

$doc = Document::new();
$page = $doc->addPage();
$page->showText('Digitally signed document', 72, 740, StandardFont::Helvetica, 18);
$page->showText('Open the signature panel in your viewer to inspect it.', 72, 710, StandardFont::Helvetica, 11);
// The stamp text is Latin, so the built-in Helvetica will do; pass a PdfFont
// for any other script.
$page->addSignatureField('Signature1', 72, 620, 260, 60, SignatureAppearance::simple(color: '#1F3A93'));
$doc->sign(new SignatureConfig($certPem, $keyPem, reason: 'Approval', location: 'Berlin'));

save_sample('06-signature.pdf', $doc->toBytes());
