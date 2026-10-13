<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdf\Pdf\Signature\Appearance;

use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Style\Alignment;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;

/**
 * How a signature field looks on the page — the signature stamp.
 *
 * A frame (border, rounded corners, background) holding blocks drawn in
 * order: {@see TextBlock}, {@see ImageBlock}, {@see QrBlock}. Text and QR data
 * may use placeholders in braces, filled when the document is signed:
 *
 *  - from the certificate: `{cn}`, `{org}`, `{email}`, `{serial}`,
 *    `{validFrom}`, `{validTo}`, `{issuer}`, and the Russian identifiers
 *    `{inn}`, `{innle}`, `{ogrn}`, `{ogrnip}`, `{snils}`;
 *  - from the signature: `{signer}` (the configured signer name, else the
 *    certificate's CN), `{signedAt}`, `{reason}`, `{location}`, `{contact}`;
 *  - any key of `$variables`.
 *
 * A field that is not being signed is drawn with its own variables only, so
 * an empty stamp can say "Place for signature".
 *
 * Text needs a font with the glyphs it uses: an embedded `PdfFont` (any
 * script; required for PDF/A) or, failing that, Helvetica, which covers only
 * WinAnsi (Latin). Inside a laid-out document the stamp falls back to the
 * document's own font.
 */
final readonly class SignatureAppearance
{
    /** @var list<AppearanceBlock> */
    public array $blocks;

    /**
     * @param  list<AppearanceBlock>  $blocks
     * @param  ?string  $borderColor  Hex colour; null — no border.
     * @param  ?string  $backgroundColor  Hex colour; null — transparent.
     * @param  ?PdfFont  $font  Regular text font.
     * @param  ?PdfFont  $boldFont  Bold text font; without it bold text is emboldened by stroking.
     * @param  string  $dateFormat  `DateTimeInterface::format()` pattern for `{validFrom}` / `{validTo}`.
     * @param  string  $dateTimeFormat  Pattern for `{signedAt}`.
     * @param  array<string, string>  $variables  Extra placeholders.
     */
    public function __construct(
        array $blocks = [],
        public ?string $borderColor = '#000000',
        public float $borderWidthPt = 0.5,
        public float $cornerRadiusPt = 0.0,
        public ?string $backgroundColor = null,
        public float $paddingPt = 3.0,
        public ?PdfFont $font = null,
        public ?PdfFont $boldFont = null,
        public string $dateFormat = 'Y-m-d',
        public string $dateTimeFormat = 'Y-m-d H:i:s P',
        public array $variables = [],
    ) {
        foreach ($blocks as $block) {
            if (! $block instanceof AppearanceBlock) {
                throw new \InvalidArgumentException('SignatureAppearance blocks must implement AppearanceBlock');
            }
        }
        $this->blocks = array_values($blocks);
        if ($borderColor !== null) {
            Color::parse($borderColor);
        }
        if ($backgroundColor !== null) {
            Color::parse($backgroundColor);
        }
        if ($borderWidthPt < 0.0 || $cornerRadiusPt < 0.0 || $paddingPt < 0.0) {
            throw new \InvalidArgumentException('Border width, corner radius and padding must not be negative');
        }
    }

    /**
     * The customary electronic-signature mark: a coloured rounded frame,
     * "DOCUMENT SIGNED WITH AN ELECTRONIC SIGNATURE" heading, then the
     * certificate serial, owner and validity period. An optional emblem sits
     * on the left. Sized for about 250 × 75 pt.
     *
     * @param  'ru'|'en'  $locale  Language of the captions.
     */
    public static function electronicSignatureStamp(
        string $locale = 'ru',
        ?PdfFont $font = null,
        ?PdfFont $boldFont = null,
        string $color = '#1F3A93',
        ?PdfImage $emblem = null,
    ): self {
        $captions = match ($locale) {
            'ru' => [
                "ДОКУМЕНТ ПОДПИСАН\nЭЛЕКТРОННОЙ ПОДПИСЬЮ",
                ['Сертификат: {serial}', 'Владелец: {cn}', 'Действителен с {validFrom} по {validTo}'],
            ],
            'en' => [
                "DOCUMENT SIGNED WITH\nAN ELECTRONIC SIGNATURE",
                ['Certificate: {serial}', 'Owner: {cn}', 'Valid from {validFrom} to {validTo}'],
            ],
            default => throw new \InvalidArgumentException("Unsupported stamp locale '$locale' (expected ru or en)"),
        };

        $textX = 0.0;
        $blocks = [];
        if ($emblem !== null) {
            $blocks[] = new ImageBlock($emblem, new Box(0.0, 0.0, 0.2, 1.0));
            $textX = 0.22;
        }
        $blocks[] = new TextBlock(
            $captions[0],
            new Box($textX, 0.0, 1.0 - $textX, 0.38),
            sizePt: 8.5,
            color: $color,
            align: Alignment::Center,
            verticalAlign: VerticalAlignment::Center,
            bold: true,
            lineHeight: 1.15,
        );
        $blocks[] = new TextBlock(
            $captions[1],
            new Box($textX, 0.42, 1.0 - $textX, 0.58),
            sizePt: 7.5,
            minSizePt: 4.5,
            color: $color,
            verticalAlign: VerticalAlignment::Center,
        );

        return new self(
            $blocks,
            borderColor: $color,
            borderWidthPt: 1.5,
            cornerRadiusPt: 6.0,
            backgroundColor: null,
            paddingPt: 5.0,
            font: $font,
            boldFont: $boldFont,
            dateFormat: 'd.m.Y',
            dateTimeFormat: 'd.m.Y H:i',
        );
    }

    /**
     * A plain stamp: "Digitally signed by …", the date, and the reason and
     * location when they are set.
     */
    public static function simple(?PdfFont $font = null, string $color = '#000000'): self
    {
        return new self(
            [new TextBlock(
                ['Digitally signed by {signer}', 'Date: {signedAt}', 'Reason: {reason}', 'Location: {location}'],
                sizePt: 8.0,
                color: $color,
                verticalAlign: VerticalAlignment::Center,
            )],
            borderColor: $color,
            borderWidthPt: 0.5,
            font: $font,
        );
    }

    /** Only a picture — a scanned signature or seal — with an optional frame. */
    public static function imageOnly(PdfImage $image, ?string $borderColor = null): self
    {
        return new self(
            [new ImageBlock($image)],
            borderColor: $borderColor,
            paddingPt: $borderColor === null ? 0.0 : 2.0,
        );
    }

    /** The same stamp drawn with other fonts. */
    public function withFonts(?PdfFont $font, ?PdfFont $boldFont = null): self
    {
        return $this->with(['font' => $font, 'boldFont' => $boldFont]);
    }

    /**
     * The same stamp with extra placeholders, merged over the existing ones.
     *
     * @param  array<string, string>  $variables
     */
    public function withVariables(array $variables): self
    {
        return $this->with(['variables' => [...$this->variables, ...$variables]]);
    }

    /** The same stamp with blocks appended on top. */
    public function withBlocks(AppearanceBlock ...$blocks): self
    {
        return $this->with(['blocks' => [...$this->blocks, ...array_values($blocks)]]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function with(array $changes): self
    {
        $args = [
            'blocks' => $this->blocks,
            'borderColor' => $this->borderColor,
            'borderWidthPt' => $this->borderWidthPt,
            'cornerRadiusPt' => $this->cornerRadiusPt,
            'backgroundColor' => $this->backgroundColor,
            'paddingPt' => $this->paddingPt,
            'font' => $this->font,
            'boldFont' => $this->boldFont,
            'dateFormat' => $this->dateFormat,
            'dateTimeFormat' => $this->dateTimeFormat,
            'variables' => $this->variables,
        ];

        return new self(...[...$args, ...$changes]);
    }
}
