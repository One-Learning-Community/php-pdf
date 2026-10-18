<?php

declare(strict_types=1);

/**
 * Torture set — documents that exercise the hardest rendering paths in one
 * place: complex tables, multilingual text (Cyrillic, Greek, Arabic, CJK),
 * barcodes, charts, SVG, AcroForm fields, digital signatures, PDF/A and
 * read/merge. Used three ways:
 *
 *  1. CI smoke: every document must render cleanly with poppler
 *     (scripts/conformance/torture-smoke.sh);
 *  2. the manual cross-viewer checklist in docs/en/VIEWER-MATRIX.md;
 *  3. a gallery of what the library can do.
 *
 * Usage: php examples/torture/generate.php [output-dir]
 *   (default output: examples/torture/out/)
 *
 * Requires fonts fetched by scripts/fetch-fonts.sh (Liberation + Amiri +
 * DroidSansFallback).
 */

use Dskripchenko\PhpPdf\Document;
use Dskripchenko\PhpPdf\Element\AreaChart;
use Dskripchenko\PhpPdf\Element\Barcode;
use Dskripchenko\PhpPdf\Element\BarcodeFormat;
use Dskripchenko\PhpPdf\Element\BarChart;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\FormField;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\LineChart;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\PieChart;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Run;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\Document as PdfDocument;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfMerger;
use Dskripchenko\PhpPdf\Pdf\Merge\PdfSource;
use Dskripchenko\PhpPdf\Pdf\PdfAConfig;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\RunStyle;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$fontDir = $root.'/.cache/fonts';
foreach (['liberation-fonts-ttf-2.1.5/LiberationSans-Regular.ttf', 'Amiri-Regular.ttf', 'DroidSansFallback.ttf'] as $needed) {
    if (! is_readable("$fontDir/$needed")) {
        fwrite(STDERR, "Missing $fontDir/$needed — run scripts/fetch-fonts.sh first.\n");
        exit(1);
    }
}

$outDir = $argv[1] ?? __DIR__.'/out';
if (! is_dir($outDir) && ! mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "Cannot create $outDir\n");
    exit(1);
}

$lib = fn (string $file) => new PdfFont(TtfFile::fromFile("$fontDir/liberation-fonts-ttf-2.1.5/$file"));

$provider = new class($fontDir) implements FontProvider {
    public function __construct(private string $dir) {}

    public function resolve(string $name): ?TtfFile
    {
        return match (strtolower($name)) {
            'arabic' => TtfFile::fromFile($this->dir.'/Amiri-Regular.ttf'),
            'cjk' => TtfFile::fromFile($this->dir.'/DroidSansFallback.ttf'),
            default => null,
        };
    }
};

$engine = new Engine(
    defaultFont: $lib('LiberationSans-Regular.ttf'),
    boldFont: $lib('LiberationSans-Bold.ttf'),
    italicFont: $lib('LiberationSans-Italic.ttf'),
    boldItalicFont: $lib('LiberationSans-BoldItalic.ttf'),
    fontProvider: $provider,
);

$bold = new RunStyle(bold: true);
$p = fn (string $text, ?RunStyle $style = null) => new Paragraph([new Run($text, $style ?? new RunStyle)]);
$cell = fn (string $text, ?RunStyle $style = null) => new Cell([new Paragraph([new Run($text, $style ?? new RunStyle)])]);

$docs = [];

// t01 — tables: header, column spans, row spans, numeric alignment fodder.
$docs['t01-tables'] = new Document(new Section([
    new Heading(1, [new Run('T01 — Tables')]),
    $p('Column spans, row spans, long wrapped content and tight columns.'),
    new Table([
        new Row([
            new Cell([new Paragraph([new Run('Quarterly summary', $bold)])], columnSpan: 3),
            $cell('Total', $bold),
        ]),
        new Row([$cell('Region', $bold), $cell('Q1', $bold), $cell('Q2', $bold), $cell('H1', $bold)]),
        new Row([$cell('EMEA — including a deliberately long region description that must wrap inside the cell'), $cell('1 204'), $cell('1 310'), $cell('2 514')]),
        new Row([
            new Cell([new Paragraph([new Run('APAC (spans two rows)')])], rowSpan: 2),
            $cell('890'), $cell('944'), $cell('1 834'),
        ]),
        new Row([$cell('— restated'), $cell('901'), $cell('1 791')]),
    ]),
]));

// t02 — Latin, Cyrillic, Greek, ligatures, combining diacritics.
$docs['t02-multilingual'] = new Document(new Section([
    new Heading(1, [new Run('T02 — Latin · Кириллица · Ελληνικά')]),
    $p('Ligatures: office floor difficult affluent. Diacritics: naïve façade Zoë coöperate.'),
    $p('Съешь же ещё этих мягких французских булок, да выпей чаю — классическая панграмма.'),
    $p('Θάλασσα, θάλασσα! Αλφάβητο: αβγδεζηθικλμνξοπρστυφχψω.'),
    $p('Mixed emphasis: ', $bold),
    new Paragraph([
        new Run('bold ', $bold),
        new Run('italic ', new RunStyle(italic: true)),
        new Run('bold-italic ', new RunStyle(bold: true, italic: true)),
        new Run('underline', new RunStyle(underline: true)),
    ]),
]));

// t03 — Arabic shaping + bidi (Amiri).
$arabic = new RunStyle(fontFamily: 'arabic');
$docs['t03-arabic-bidi'] = new Document(new Section([
    new Heading(1, [new Run('T03 — Arabic shaping and bidi')]),
    new Paragraph([new Run('مرحبا بالعالم — التشكيل العربي مع الأرقام ١٢٣ والاتجاهين', $arabic)]),
    new Paragraph([
        new Run('Latin lead-in, then ')
        , new Run('نص عربي في المنتصف', $arabic),
        new Run(', then Latin tail.'),
    ]),
]));

// t04 — CJK via font provider.
$cjk = new RunStyle(fontFamily: 'cjk');
$docs['t04-cjk'] = new Document(new Section([
    new Heading(1, [new Run('T04 — CJK')]),
    new Paragraph([new Run('简体中文：你好世界，可移植文档格式生成测试。', $cjk)]),
    new Paragraph([new Run('日本語：こんにちは世界。組版のテストです。', $cjk)]),
    new Paragraph([new Run('한국어 없음 — DroidSansFallback covers CJK ideographs and kana.', new RunStyle)]),
]));

// t05 — barcodes, linear + 2D.
$docs['t05-barcodes'] = new Document(new Section([
    new Heading(1, [new Run('T05 — Barcodes')]),
    $p('Code128:'),
    new Barcode('PHP-PDF-1234', BarcodeFormat::Code128),
    $p('EAN-13:'),
    new Barcode('4006381333931', BarcodeFormat::Ean13),
    $p('QR:'),
    new Barcode('https://github.com/dskripchenko/php-pdf', BarcodeFormat::Qr, heightPt: 80),
    $p('DataMatrix:'),
    new Barcode('php-pdf torture', BarcodeFormat::DataMatrix, heightPt: 60),
    $p('PDF417:'),
    new Barcode('php-pdf PDF417 sample', BarcodeFormat::Pdf417, heightPt: 50),
]));

// t06 — charts.
$docs['t06-charts'] = new Document(new Section([
    new Heading(1, [new Run('T06 — Charts')]),
    new PieChart([
        ['label' => 'MIT', 'value' => 60],
        ['label' => 'GPL', 'value' => 25],
        ['label' => 'Proprietary', 'value' => 15],
    ], title: 'License share'),
    new BarChart(bars: [
        ['label' => 'php-pdf', 'value' => 10.8],
        ['label' => 'mpdf', 'value' => 61.1],
        ['label' => 'tcpdf', 'value' => 36.1],
    ], title: 'HTML render, ms'),
    new LineChart(points: [
        ['label' => 'Jan', 'value' => 3],
        ['label' => 'Feb', 'value' => 7],
        ['label' => 'Mar', 'value' => 4],
        ['label' => 'Apr', 'value' => 11],
    ], title: 'Trend'),
    new AreaChart(
        xLabels: ['Q1', 'Q2', 'Q3'],
        series: [
            ['name' => 'Generate', 'values' => [4, 9, 6]],
            ['name' => 'Merge', 'values' => [1, 3, 5]],
        ],
        title: 'Area',
    ),
]));

// t07 — SVG.
$docs['t07-svg'] = new Document(new Section([
    new Heading(1, [new Run('T07 — SVG')]),
    new SvgElement(<<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 120">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#4a90d9"/>
              <stop offset="1" stop-color="#153a5f"/>
            </linearGradient>
          </defs>
          <rect x="10" y="10" width="180" height="100" rx="12" fill="url(#g)"/>
          <circle cx="60" cy="60" r="30" fill="#ffffff" fill-opacity="0.85"/>
          <path d="M 110 40 q 30 -25 60 0 t -20 50 z" fill="#e8b23a" stroke="#7a5b12" stroke-width="2"/>
          <text x="100" y="112" text-anchor="middle" font-size="10" fill="#333333">gradient + path + text</text>
        </svg>
        SVG, widthPt: 300, heightPt: 180),
]));

// t08 — AcroForm fields.
$docs['t08-forms'] = new Document(new Section([
    new Heading(1, [new Run('T08 — AcroForm')]),
    $p('Name:'),
    new FormField('name', FormField::TYPE_TEXT, defaultValue: 'Jane Roe'),
    $p('Subscribe:'),
    new FormField('subscribe', FormField::TYPE_CHECKBOX),
    $p('Comment:'),
    new FormField('comment', FormField::TYPE_TEXT_MULTILINE, heightPt: 60),
]));

// t12 — HTML with CSS: grid, wrapping flex, float, fixed and absolute
// positioning, generated content, borders, a table over pages.
$docs['t12-html-css'] = Document::fromHtml(<<<'HTML'
<style>
@page { size: A4; margin: 36pt 40pt }
body { font-size: 10pt; color: #222; line-height: 1.3 }
h1 { font-size: 18pt; border-bottom: 2pt double #1f4e79; padding-bottom: 4pt }
h2 { font-size: 13pt; color: #1f4e79; margin: 10pt 0 4pt }
h2::before { content: counter(x) "§ " }
.stamp { position: fixed; top: -24pt; right: 0; width: 120pt; font-size: 7pt; color: #888; text-align: right }
.grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 6pt }
.grid > div { background: #eef4fb; border: 0.75pt solid #9db7d5; padding: 4pt }
.wide { grid-column: span 2 }
.cards { display: flex; flex-wrap: wrap; gap: 6pt }
.card { width: 30%; border: 1pt dashed #c0504d; padding: 4pt }
.note { float: right; width: 140pt; border: 1pt dotted #555; padding: 4pt; margin-left: 8pt; background: #fff8e1 }
.box { position: relative; border: 1pt solid #999; padding: 6pt; margin-top: 8pt }
.badge { position: absolute; top: 0; right: 0; width: 60pt; background: #c0504d; color: #fff; text-align: center; font-size: 7pt }
.req::after { content: " *"; color: #c00 }
q::before { content: open-quote } q::after { content: close-quote }
a[href]::after { content: " (" attr(href) ")"; font-size: 8pt; color: #666 }
table { border-collapse: collapse; width: 100% }
th, td { border: 0.75pt solid #777; padding: 3pt 5pt }
thead th { background: #1f4e79; color: #fff }
tr:nth-child(even) td { background: #f2f2f2 }
.keep { break-inside: avoid; border: 1pt solid #1f4e79; padding: 6pt; margin-top: 8pt }
</style>
<div class="stamp">php-pdf torture · t12</div>
<h1>HTML + CSS layout — Вёрстка из HTML</h1>
<p>Stylesheet cascade, inheritance, <span class="req">required field</span>, <q>quoted text</q>, a link to <a href="https://github.com/dskripchenko/php-pdf">php-pdf</a>, Кириллица и ελληνικά.</p>
<h2>Grid</h2>
<div class="grid"><div>2fr column</div><div>1fr</div><div>1fr</div><div class="wide">spans two columns</div><div>last</div></div>
<h2>Wrapping flex</h2>
<div class="cards"><div class="card">Card one</div><div class="card">Card two</div><div class="card">Card three</div><div class="card">Card four</div><div class="card">Card five</div></div>
<h2>Float and positioning</h2>
<div class="note">A note floated right, with a dotted border and a background.</div>
<p>Text beside the float. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
<p style="clear: both">Cleared paragraph below the float.</p>
<div class="box"><div class="badge">NEW</div><p>A box with an absolutely placed badge in its corner.</p></div>
<h2>Table across pages</h2>
<table><thead><tr><th>#</th><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody><tr><td>1</td><td>Item 1 — позиция</td><td style="text-align:right">3</td><td style="text-align:right">7.50</td></tr><tr><td>2</td><td>Item 2 — позиция</td><td style="text-align:right">6</td><td style="text-align:right">14.50</td></tr><tr><td>3</td><td>Item 3 — позиция</td><td style="text-align:right">9</td><td style="text-align:right">21.50</td></tr><tr><td>4</td><td>Item 4 — позиция</td><td style="text-align:right">12</td><td style="text-align:right">28.50</td></tr><tr><td>5</td><td>Item 5 — позиция</td><td style="text-align:right">15</td><td style="text-align:right">35.50</td></tr><tr><td>6</td><td>Item 6 — позиция</td><td style="text-align:right">18</td><td style="text-align:right">42.50</td></tr><tr><td>7</td><td>Item 7 — позиция</td><td style="text-align:right">21</td><td style="text-align:right">49.50</td></tr><tr><td>8</td><td>Item 8 — позиция</td><td style="text-align:right">24</td><td style="text-align:right">56.50</td></tr><tr><td>9</td><td>Item 9 — позиция</td><td style="text-align:right">27</td><td style="text-align:right">63.50</td></tr><tr><td>10</td><td>Item 10 — позиция</td><td style="text-align:right">30</td><td style="text-align:right">70.50</td></tr><tr><td>11</td><td>Item 11 — позиция</td><td style="text-align:right">33</td><td style="text-align:right">77.50</td></tr><tr><td>12</td><td>Item 12 — позиция</td><td style="text-align:right">36</td><td style="text-align:right">84.50</td></tr><tr><td>13</td><td>Item 13 — позиция</td><td style="text-align:right">39</td><td style="text-align:right">91.50</td></tr><tr><td>14</td><td>Item 14 — позиция</td><td style="text-align:right">42</td><td style="text-align:right">98.50</td></tr><tr><td>15</td><td>Item 15 — позиция</td><td style="text-align:right">45</td><td style="text-align:right">105.50</td></tr><tr><td>16</td><td>Item 16 — позиция</td><td style="text-align:right">48</td><td style="text-align:right">112.50</td></tr><tr><td>17</td><td>Item 17 — позиция</td><td style="text-align:right">51</td><td style="text-align:right">119.50</td></tr><tr><td>18</td><td>Item 18 — позиция</td><td style="text-align:right">54</td><td style="text-align:right">126.50</td></tr><tr><td>19</td><td>Item 19 — позиция</td><td style="text-align:right">57</td><td style="text-align:right">133.50</td></tr><tr><td>20</td><td>Item 20 — позиция</td><td style="text-align:right">60</td><td style="text-align:right">140.50</td></tr><tr><td>21</td><td>Item 21 — позиция</td><td style="text-align:right">63</td><td style="text-align:right">147.50</td></tr><tr><td>22</td><td>Item 22 — позиция</td><td style="text-align:right">66</td><td style="text-align:right">154.50</td></tr><tr><td>23</td><td>Item 23 — позиция</td><td style="text-align:right">69</td><td style="text-align:right">161.50</td></tr><tr><td>24</td><td>Item 24 — позиция</td><td style="text-align:right">72</td><td style="text-align:right">168.50</td></tr><tr><td>25</td><td>Item 25 — позиция</td><td style="text-align:right">75</td><td style="text-align:right">175.50</td></tr><tr><td>26</td><td>Item 26 — позиция</td><td style="text-align:right">78</td><td style="text-align:right">182.50</td></tr><tr><td>27</td><td>Item 27 — позиция</td><td style="text-align:right">81</td><td style="text-align:right">189.50</td></tr><tr><td>28</td><td>Item 28 — позиция</td><td style="text-align:right">84</td><td style="text-align:right">196.50</td></tr><tr><td>29</td><td>Item 29 — позиция</td><td style="text-align:right">87</td><td style="text-align:right">203.50</td></tr><tr><td>30</td><td>Item 30 — позиция</td><td style="text-align:right">90</td><td style="text-align:right">210.50</td></tr><tr><td>31</td><td>Item 31 — позиция</td><td style="text-align:right">93</td><td style="text-align:right">217.50</td></tr><tr><td>32</td><td>Item 32 — позиция</td><td style="text-align:right">96</td><td style="text-align:right">224.50</td></tr><tr><td>33</td><td>Item 33 — позиция</td><td style="text-align:right">99</td><td style="text-align:right">231.50</td></tr><tr><td>34</td><td>Item 34 — позиция</td><td style="text-align:right">102</td><td style="text-align:right">238.50</td></tr><tr><td>35</td><td>Item 35 — позиция</td><td style="text-align:right">105</td><td style="text-align:right">245.50</td></tr><tr><td>36</td><td>Item 36 — позиция</td><td style="text-align:right">108</td><td style="text-align:right">252.50</td></tr><tr><td>37</td><td>Item 37 — позиция</td><td style="text-align:right">111</td><td style="text-align:right">259.50</td></tr><tr><td>38</td><td>Item 38 — позиция</td><td style="text-align:right">114</td><td style="text-align:right">266.50</td></tr><tr><td>39</td><td>Item 39 — позиция</td><td style="text-align:right">117</td><td style="text-align:right">273.50</td></tr><tr><td>40</td><td>Item 40 — позиция</td><td style="text-align:right">120</td><td style="text-align:right">280.50</td></tr><tr><td>41</td><td>Item 41 — позиция</td><td style="text-align:right">123</td><td style="text-align:right">287.50</td></tr><tr><td>42</td><td>Item 42 — позиция</td><td style="text-align:right">126</td><td style="text-align:right">294.50</td></tr><tr><td>43</td><td>Item 43 — позиция</td><td style="text-align:right">129</td><td style="text-align:right">301.50</td></tr><tr><td>44</td><td>Item 44 — позиция</td><td style="text-align:right">132</td><td style="text-align:right">308.50</td></tr><tr><td>45</td><td>Item 45 — позиция</td><td style="text-align:right">135</td><td style="text-align:right">315.50</td></tr></tbody></table>
<div class="keep"><h2>Kept together</h2><p>This box has break-inside: avoid and stays on one page.</p><p>Second line of the kept box.</p><p>Third line of the kept box.</p></div>
HTML);

$emitted = [];
$failures = 0;
foreach ($docs as $name => $document) {
    try {
        $bytes = $document->toBytes($engine);
        file_put_contents("$outDir/$name.pdf", $bytes);
        $emitted[$name] = $bytes;
        echo "generated $name.pdf\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "FAILED $name: {$e->getMessage()}\n");
        $failures++;
    }
}

// t09 — PDF/A-2u with real content (same engine, archival profile).
try {
    $iccPath = $root.'/resources/icc/sRGB2014.icc';
    $pdfa = new Document(
        new Section([
            new Heading(1, [new Run('T09 — PDF/A-2u')]),
            $p('Archival document with embedded fonts, Cyrillic — привет — and a table.'),
            new Table([
                new Row([$cell('Standard', $bold), $cell('ISO 19005-2')]),
                new Row([$cell('Conformance'), $cell('U (Unicode)')]),
            ]),
        ]),
        lang: 'en',
        pdfA: new PdfAConfig(
            $iccPath,
            iccProfileName: 'sRGB2014',
            title: 'T09 — PDF/A-2u torture',
            author: 'dskripchenko/php-pdf',
            part: PdfAConfig::PART_2,
            conformance: PdfAConfig::CONFORMANCE_U,
        ),
    );
    $bytes = $pdfa->toBytes($engine);
    file_put_contents("$outDir/t09-pdfa-2u.pdf", $bytes);
    $emitted['t09-pdfa-2u'] = $bytes;
    echo "generated t09-pdfa-2u.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t09-pdfa-2u: {$e->getMessage()}\n");
    $failures++;
}

// t10 — signed document (self-signed certificate, generated on the fly).
try {
    $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'php-pdf torture signer'], $pkey, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $pkey, 365, ['digest_alg' => 'sha256']);
    $certPem = '';
    $keyPem = '';
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($pkey, $keyPem);

    $pdf = PdfDocument::new();
    $page = $pdf->addPage();
    $page->showText('T10 - digitally signed (self-signed demo certificate)', 60, 760, \Dskripchenko\PhpPdf\Pdf\StandardFont::Helvetica, 14);
    $page->addFormField('signature', 'Signature1', 60, 680, 240, 60);
    $pdf->sign(new SignatureConfig($certPem, $keyPem));
    $bytes = $pdf->toBytes();
    file_put_contents("$outDir/t10-signed.pdf", $bytes);
    $emitted['t10-signed'] = $bytes;
    echo "generated t10-signed.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t10-signed: {$e->getMessage()}\n");
    $failures++;
}

// t11 — merge + stamp: combine two torture docs, stamp a generated overlay.
try {
    if (! isset($emitted['t01-tables'], $emitted['t05-barcodes'])) {
        throw new \RuntimeException('prerequisite documents failed');
    }
    $stampSrc = new Document(new Section([
        new Paragraph([new Run('MERGED BY PHP-PDF', new RunStyle(bold: true, color: '#c0392b'))]),
    ]));
    $merged = PdfMerger::create()
        ->append(PdfSource::fromBytes($emitted['t01-tables']))
        ->append(PdfSource::fromBytes($emitted['t05-barcodes']))
        ->stamp(PdfSource::fromBytes($stampSrc->toBytes($engine)))
        ->toBytes();
    file_put_contents("$outDir/t11-merged.pdf", $merged);
    echo "generated t11-merged.pdf\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED t11-merged: {$e->getMessage()}\n");
    $failures++;
}

exit($failures > 0 ? 1 : 0);
