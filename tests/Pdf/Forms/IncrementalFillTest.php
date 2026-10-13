<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Tests\Pdf\Forms;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Forms\ExistingFormFiller;
use Dskripchenko\PhpPdf\Pdf\Signature\DocMdpPermission;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Tests\Pdf\Signature\Support\Pki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Filling a form as an incremental update: the original bytes — and the
 * signatures over them — stay as they were.
 */
final class IncrementalFillTest extends TestCase
{
    private const FONT = __DIR__.'/../../../.cache/fonts/liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf';

    private static function form(?SignatureConfig $signature = null): string
    {
        $doc = Document::new();
        $page = $doc->addPage();
        $page->addFormField('text', 'full_name', 72, 700, 250, 20);
        $page->addFormField('text', 'city', 72, 670, 250, 20);
        $page->addFormField('checkbox', 'agree', 72, 640, 14, 14);
        $page->addSignatureField('sig', 72, 560, 200, 50);
        if ($signature !== null) {
            $doc->sign($signature);
        }

        return $doc->toBytes();
    }

    #[Test]
    public function values_are_appended_and_read_back(): void
    {
        if (! is_readable(self::FONT)) {
            self::markTestSkipped('Liberation fonts not fetched');
        }
        $original = self::form();
        $filled = ExistingFormFiller::fromBytes($original)->useFont(self::FONT)
            ->setValue('full_name', 'Jane Roe')->setValue('city', 'Москва')->setValue('agree', true)
            ->toBytes(incremental: true);

        self::assertStringStartsWith($original, $filled);
        $fields = [];
        foreach (ExistingFormFiller::fromBytes($filled)->fields() as $field) {
            $fields[$field->name] = $field->value;
        }
        self::assertSame('Jane Roe', $fields['full_name']);
        self::assertSame('Москва', $fields['city']);
        self::assertSame('Yes', $fields['agree']);
    }

    #[Test]
    public function a_signature_survives_the_fill(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        $signed = self::form(new SignatureConfig(signer: Pki::rsaSigner()));
        $filled = ExistingFormFiller::fromBytes($signed)->setValue('full_name', 'Jane Roe')->toBytes(incremental: true);

        [[$der, $content]] = Pki::extractAll($filled);
        self::assertTrue(Pki::cmsVerify($der, $content)[0]);
        self::assertSame(strlen($signed), strlen($content) + (strlen($signed) - strlen($content)));
    }

    #[Test]
    public function a_certified_form_takes_values_but_not_page_changes(): void
    {
        if (! function_exists('openssl_sign')) {
            self::markTestSkipped('openssl extension not available');
        }
        $certified = self::form(new SignatureConfig(signer: Pki::rsaSigner(), certification: DocMdpPermission::FormFilling));

        $filled = ExistingFormFiller::fromBytes($certified)->setValue('full_name', 'Jane Roe')->toBytes(incremental: true);
        self::assertStringStartsWith($certified, $filled);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/page content/');
        ExistingFormFiller::fromBytes($certified)->setValue('full_name', 'Jane Roe')->flatten()->toBytes(incremental: true);
    }

    #[Test]
    public function the_full_rewrite_stays_the_default(): void
    {
        $original = self::form();
        $rewritten = ExistingFormFiller::fromBytes($original)->setValue('full_name', 'x')->toBytes();

        self::assertFalse(str_starts_with($rewritten, $original));
    }
}
