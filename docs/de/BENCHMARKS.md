# Benchmarks

> Erzeugt von `scripts/bench/run.php` — die Zahlen nicht von Hand ändern.
> Reproduzierbar mit `composer bench` aus einem sauberen Klon.

Ein reproduzierbarer Vergleich von `dskripchenko/php-pdf` mit den vier
am häufigsten genutzten PHP-PDF-Bibliotheken auf Packagist:
`mpdf/mpdf`, `tecnickcom/tcpdf`, `dompdf/dompdf` und `setasign/fpdf`.

## Methodik

Jedes (Bibliothek, Szenario)-Paar wird **5-mal** in einem eigenen
PHP-Subprozess ausgeführt, nach **1 Aufwärmlauf**. Berichtet werden der
Median der Wall-Time und der Speicherspitzenwert. Die Ausgabegröße ist
die Byte-Anzahl des erzeugten PDFs.

Die Subprozess-Isolation ist wichtig: Ein einzelner, langlaufender
PHP-Prozess sammelt geladenen Code und OPcache-Druck an, was den
Speicherspitzenwert desjenigen Szenarios verfälscht, das zuletzt
ausgeführt wird. Das Starten eines frischen Prozesses pro Lauf macht
die Speicherspalte aussagekräftig.

Die Wall-Time wird mit `hrtime(true)` gemessen, der Speicher mit
`memory_get_peak_usage(true)`. Die Versionen der Vergleichsbibliotheken
sind in `scripts/bench/composer.lock` festgeschrieben, das im Repository
eingecheckt ist.

## Szenarien

| Schlüssel | Beschreibung                                           |
|-----------|--------------------------------------------------------|
| `hello`   | Einzelne A4-Seite, ein `Hello, world!`-Absatz.        |
| `invoice` | 100 Seiten × 50 Zeilen; jede Seite hat eine `<h1>`-Überschrift und eine 4-spaltige Tabelle. |
| `images`  | 20 Seiten × 4 JPEG-Thumbnails (je 250 × 180 pt).      |
| `html`    | Ein `<h1>`, zwei `<h2>`-Abschnitte, 24 Lorem-Absätze mit Fett/Kursiv/Link sowie Aufzählungslisten. ~5 Seiten. |

FPDF unterstützt kein HTML und wird daher beim `html`-Szenario
übersprungen.

## Reproduktion

```bash
git clone https://github.com/dskripchenko/php-pdf.git && cd php-pdf
composer install
composer bench          # installs pinned competitors, runs, prints tables
```

Maschinenlesbare Ergebnisse landen in `scripts/bench/out/results.{json,csv}`.

**Umgebung (2026-10-18):**

- PHP 8.5.10 (CLI, OPcache deaktiviert (CLI-Standard))
- Darwin 27.0.0 arm64
- dskripchenko/php-pdf v1.19.1
- dompdf/dompdf v3.1.5
- mpdf/mpdf v8.3.1
- setasign/fpdf 1.9.0
- tecnickcom/tcpdf 6.11.3

## Ergebnisse

### Hello world (eine Seite, ein Absatz)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1.3 KB |
| phppdf | 5.0 | 8.0 MB | 0.8 KB |
| dompdf | 11.7 | 8.0 MB | 1.1 KB |
| tcpdf | 15.0 | 16.0 MB | 6.5 KB |
| mpdf | 29.4 | 20.0 MB | 14 KB |

### 100-seitige Rechnung (50 Zeilen/Seite)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 26.4 | 6.0 MB | 198 KB |
| phppdf | 632.4 | 50.0 MB | 194 KB |
| tcpdf | 1342.2 | 26.0 MB | 462 KB |
| mpdf | 2299.5 | 30.0 MB | 811 KB |
| dompdf | 9156.0 | 350.0 MB | 2316 KB |

### Bildraster (20 Seiten × 4 JPEGs)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 7.0 KB |
| phppdf | 7.7 | 8.0 MB | 8.8 KB |
| tcpdf | 16.9 | 16.0 MB | 33 KB |
| dompdf | 31.8 | 10.0 MB | 4.7 KB |
| mpdf | 36.5 | 20.0 MB | 24 KB |

### HTML → PDF Artikel (~5 Seiten)

| Bibliothek | Wall-Time (ms) | Speicherspitze | Ausgabegröße |
|---|---:|---:|---:|
| phppdf | 15.2 | 8.0 MB | 12 KB |
| tcpdf | 38.0 | 16.0 MB | 49 KB |
| dompdf | 48.1 | 12.0 MB | 19 KB |
| mpdf | 61.9 | 20.0 MB | 55 KB |
| fpdf | _übersprungen_ (nicht implementiert) | — | — |

---

Sprache: [English](../en/BENCHMARKS.md) · [Русский](../ru/BENCHMARKS.md) · [中文](../zh/BENCHMARKS.md) · [Deutsch](BENCHMARKS.md)
