# 基准测试

> 由 `scripts/bench/run.php` 生成 —— 请勿手动修改数字。
> 在全新克隆的仓库中运行 `composer bench` 即可复现。

将 `dskripchenko/php-pdf` 与 Packagist 上使用最广泛的四个 PHP PDF
库进行可复现的对比：`mpdf/mpdf`、`tecnickcom/tcpdf`、
`dompdf/dompdf` 与 `setasign/fpdf`。

## 方法论

每一对（库，场景）在各自独立的 PHP 子进程中运行 **5 次**，
并先做 **1 次热身**。报告墙钟时间与峰值内存的中位数。
输出大小为所生成 PDF 的字节数。

子进程隔离很重要：单一长时间运行的 PHP 进程会累积已加载代码与
OPcache 压力，从而拉高最后运行的那个场景的内存峰值。为每次运行
新建进程，能让内存这一列变得有意义。

墙钟时间用 `hrtime(true)` 测量；内存用
`memory_get_peak_usage(true)` 测量。对比库的版本锁定在
`scripts/bench/composer.lock` 中，该文件已提交到仓库。

## 场景

| 键名      | 描述                                                  |
|-----------|--------------------------------------------------------|
| `hello`   | 单页 A4，一段 `Hello, world!`。                        |
| `invoice` | 100 页 × 50 行；每页有一个 `<h1>` 标题和一个 4 列表格。 |
| `images`  | 20 页 × 4 张 JPEG 缩略图（每张 250 × 180 pt）。         |
| `html`    | 一个 `<h1>`、两个 `<h2>` 小节、24 段带粗体/斜体/链接的 Lorem 文本，外加项目符号列表。约 5 页。 |

FPDF 不支持 HTML，因此在 `html` 场景中跳过。

## 如何复现

```bash
git clone https://github.com/dskripchenko/php-pdf.git && cd php-pdf
composer install
composer bench          # installs pinned competitors, runs, prints tables
```

机器可读的输出位于 `scripts/bench/out/results.{json,csv}`。

**测试环境（2026-10-18）：**

- PHP 8.5.10（CLI，opcache 禁用（CLI 默认））
- Darwin 27.0.0 arm64
- dskripchenko/php-pdf v1.19.1
- dompdf/dompdf v3.1.5
- mpdf/mpdf v8.3.1
- setasign/fpdf 1.9.0
- tecnickcom/tcpdf 6.11.3

## 结果

### Hello world（单页，一段文字）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 0.9 | 4.0 MB | 1.3 KB |
| phppdf | 5.0 | 8.0 MB | 0.8 KB |
| dompdf | 11.7 | 8.0 MB | 1.1 KB |
| tcpdf | 15.0 | 16.0 MB | 6.5 KB |
| mpdf | 29.4 | 20.0 MB | 14 KB |

### 100 页发票（每页 50 行）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 26.4 | 6.0 MB | 198 KB |
| phppdf | 632.4 | 50.0 MB | 194 KB |
| tcpdf | 1342.2 | 26.0 MB | 462 KB |
| mpdf | 2299.5 | 30.0 MB | 811 KB |
| dompdf | 9156.0 | 350.0 MB | 2316 KB |

### 图片网格（20 页 × 4 张 JPEG）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| fpdf | 1.0 | 4.0 MB | 7.0 KB |
| phppdf | 7.7 | 8.0 MB | 8.8 KB |
| tcpdf | 16.9 | 16.0 MB | 33 KB |
| dompdf | 31.8 | 10.0 MB | 4.7 KB |
| mpdf | 36.5 | 20.0 MB | 24 KB |

### HTML → PDF 文章（约 5 页）

| 库 | 墙钟时间 (ms) | 峰值内存 | 输出大小 |
|---|---:|---:|---:|
| phppdf | 15.2 | 8.0 MB | 12 KB |
| tcpdf | 38.0 | 16.0 MB | 49 KB |
| dompdf | 48.1 | 12.0 MB | 19 KB |
| mpdf | 61.9 | 20.0 MB | 55 KB |
| fpdf | _跳过_（未实现） | — | — |

---

语言：[English](../en/BENCHMARKS.md) · [Русский](../ru/BENCHMARKS.md) · [中文](BENCHMARKS.md) · [Deutsch](../de/BENCHMARKS.md)
