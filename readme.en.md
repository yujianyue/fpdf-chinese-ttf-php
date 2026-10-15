# FPDF Chinese Extension Library · User Manual

> Version: FPDF `1.84` + `PDF_Chinese` Chinese extension (compatibility-fixed build + UTF-8/TTF embedded-font build)
> PHP supported: **5.5 ~ 8.3** (verified to generate PDF under PHP 8.3 with zero `E_ALL` warnings)
> Original authors: Olivier PLATHEY (FPDF) / yujianyue (Chinese extension)
> Maintenance note: The `fpdf.php`, `chinese.php`, and `ttf.php` shipped with this manual are enhanced builds; see "Changelog" at the end of this document.

> Chinese version of this manual: see [`说明书.md`](说明书.md).

---

## 1. What Is This

This library consists of three parts:

| File | Purpose |
| --- | --- |
| `fpdf.php` | FPDF core library (a pure-PHP PDF generator, with no dependency on any external PDF component) |
| `chinese.php` | `PDF_Chinese` Chinese extension, extending `FPDF`: keeps the legacy GB / Big5 (EUC) CID scheme and **additionally** provides **UTF-8 + TrueType/OpenType embedded-font** support |
| `ttf.php` | `TTFontSubsetter`: a pure-PHP TrueType/OpenType parser and subsetter, used for embedded fonts |

Two Chinese output routes are provided; pick the one that fits your needs:

| Route | Encoding | Embed font? | Size | Reader requirement | Best for |
| --- | --- | --- | --- | --- | --- |
| **A. Embedded TrueType (recommended)** | **Native UTF-8** | ✅ Subset-embedded | Small (tens of KB) | None | General purpose, cross-platform, consistent rendering |
| B. CID reference (GB/Big5) | GBK / Big5 | ❌ Not embedded | Minimal | Requires Adobe Asian font | Minimal size, controlled reader environment |

> Route A is the headline upgrade of this build: **no longer depends on the reader's fonts, consistent CJK/Latin rendering, native UTF-8** — see Section 7.

---

## 2. Environment Requirements

| Item | Requirement | Note |
| --- | --- | --- |
| PHP | **≥ 5.5** | Verified on 5.5 / 7.0 / 7.4 / 8.0 / 8.1 / 8.2 / 8.3 |
| zlib | Optional | When enabled, automatically compresses PDF content and embedded-font streams (`SetCompression(true)`, on by default) |
| GD | Optional | Only needed when embedding **GIF** via `Image()`; JPEG/PNG do not need it |
| Font file | Required by Route A | One `.ttf` / `.otf` / `.ttc` file that contains CJK glyphs (e.g. WenQuanYi Micro Hei) |
| Core-font directory `font/` | Required only when using core fonts such as Arial/Helvetica/Times/Courier | Must contain the official FPDF 14 core-font metric files |

> Routes A (`AddFontTTF`) and B (`AddGBFont`) **do not** need the `font/` directory.
> `ttf.php` is pure PHP and does not depend on `mbstring`, `intl`, or other extensions.

---

## 3. Installation & Directory Structure

Place the three files in your project and `require` them (`chinese.php` auto-loads `ttf.php`):

```php
require('fpdf.php');
require('chinese.php');
```

Recommended directory structure:

```
your_project/
├── fpdf.php
├── chinese.php
├── ttf.php
├── fonts/                 # your own font files (.ttf/.otf/.ttc)
│   └── wqy-microhei.ttf
└── font/                  # needed only when using core fonts
    └── ...
```

---

## 4. Quick Start

### 4.1 Chinese PDF (embedded TrueType, native UTF-8 · recommended)

```php
<?php
require('fpdf.php');
require('chinese.php');

$pdf = new PDF_Chinese();
$pdf->AddFontTTF('wqy', '', 'fonts/wqy-microhei.ttf');   // register embedded font
$pdf->AddPage();
$pdf->SetFont('wqy', '', 20);
$pdf->Cell(0, 12, '中文 UTF-8 原生输入，无需 iconv');      // pass UTF-8 directly
$pdf->Ln(16);
$pdf->SetFont('wqy', '', 12);
$pdf->MultiCell(0, 8, '中英混排 Mixed text 自动换行，宽度按码点精确计算。');
$pdf->Output('F', 'demo.pdf');
```

Verify with `pdffonts demo.pdf`: you should see `emb=yes sub=yes uni=yes` (subset-embedded with a ToUnicode map, so text is selectable and searchable).

### 4.2 Chinese PDF (CID reference, GBK encoding · legacy)

```php
<?php
require('fpdf.php');
require('chinese.php');

$pdf = new PDF_Chinese();
$pdf->AddGBFont();                        // register GB Chinese font (no font file needed)
$pdf->AddPage();
$pdf->SetFont('GB', '', 20);
$pdf->Cell(0, 12, iconv('UTF-8', 'GBK', '中文测试标题'));
$pdf->Output('D', 'demo.pdf');
```

### 4.3 Registering a font from in-memory bytes

```php
$bytes = file_get_contents('fonts/wqy-microhei.ttf');    // or read from DB / remote
$pdf->AddFontFromStream('wqy', '', $bytes);
$pdf->SetFont('wqy', '', 14);
```

---

## 5. Core API Reference (FPDF public methods)

> The commonly used methods are listed below; for the complete list, refer to the `fpdf.php` source. Default parameter values match official FPDF 1.84.

| Method | Description |
| --- | --- |
| `AddPage($orientation='', $size='', $rotation=0)` | Add a page, with optional orientation (L/P), size (A3/A4/A5/Letter…), and rotation angle |
| `SetFont($family, $style='', $size=0)` | Set font. Core fonts: `Arial/Helvetica/...`; embedded font: the family registered via `AddFontTTF`; CID Chinese: `GB`/`Big5` |
| `SetFontSize($size)` | Change font size only |
| `Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')` | Output a cell (single-line text) |
| `MultiCell($w, $h, $txt, $border=0, $align='L', $fill=false)` | Multi-line text with automatic wrapping (re-implemented for embedded fonts and the Chinese build) |
| `Write($h, $txt, $link='')` | Streaming output with automatic wrapping (same as above) |
| `Ln($h=null)` | Line break |
| `SetXY($x, $y)` / `SetX` / `SetY` | Set cursor coordinates |
| `Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')` | Insert an image (JPEG/PNG/GIF) |
| `Line($x1,$y1,$x2,$y2)` / `Rect(...)` | Draw a line / rectangle |
| `SetMargins($left,$top,$right=null)` | Page margins |
| `SetAutoPageBreak($auto, $margin=0)` | Auto page break |
| `SetTitle/SetAuthor/SetSubject/SetKeywords/SetCreator($v, $isUTF8=false)` | Document metadata |
| `AliasNbPages($alias='{nb}')` | Total-page-count placeholder (for footer `{nb}`) |
| `Output($dest='', $name='', $isUTF8=false)` | Output. `I` inline, `D` download, `F` save to file, `S` return string |
| `Error($msg)` | Raise an FPDF error (terminates the script) |

Coordinate unit is **millimeter (mm)**, origin at the top-left corner of the page.

---

## 6. Chinese-only API (PDF_Chinese · Route B)

| Method | Description |
| --- | --- |
| `AddGBFont($family='GB', $name='STSongStd-Light-Acro')` | Register a Simplified-Chinese (GB) proportional-width font |
| `AddGBhwFont($family='GB-hw', ...)` | Register a Simplified-Chinese half-width Latin font |
| `AddBig5Font($family='Big5', $name='MSungStd-Light-Acro')` | Register a Traditional-Chinese (Big5) font |
| `AddBig5hwFont($family='Big5-hw', ...)` | Register a Traditional-Chinese half-width Latin font |
| `GetMBStringWidth($s)` | Multi-byte string width (internal) |
| `MBMultiCell(...)` / `MBWrite(...)` | Multi-byte versions of `MultiCell` / `Write` (internal) |

---

## 7. UTF-8 + TrueType/OpenType Embedded Fonts (Route A · core new feature in this build)

### 7.1 What It Solves

The three pain points of the legacy scheme (Route B) are all eliminated:

| Pain point (legacy) | This build |
| --- | --- |
| Depends on the reader having Adobe Asian fonts; missing fonts render as boxes | Fonts are **subset and embedded** into the PDF, giving identical rendering on any reader/device |
| UTF-8 source must first be `iconv('UTF-8','GBK',...)` | **Native UTF-8 input/output** — pass the string directly |
| Line breaking assumes "non-ASCII = 2 bytes", inaccurate for mixed text | Width and line breaking are computed per **code point (grapheme)**, supporting 1–4 bytes (including emoji code points) |

### 7.2 API

| Method | Description |
| --- | --- |
| `AddFontTTF($family, $style='', $file='', $subset=true)` | Register from a font file (`.ttf` / `.otf` / `.ttc` supported; for TTC, the 0th font is used) |
| `AddFontFromStream($family, $style='', $data='', $subset=true)` | Register from in-memory bytes (font pulled from DB / remote) |

After registration, use `SetFont($family, $style, $size)` as usual. `Cell` / `MultiCell` / `Write` / `GetStringWidth` all automatically take the UTF-8 path — the calling convention is exactly the same as for built-in fonts.

### 7.3 Implementation notes (for understanding only; does not affect usage)

- **Encoding**: a `Type0` composite font with `Identity-H`; text is written into the content stream as 2-byte big-endian glyph IDs.
- **Embedding**: TrueType (glyf outlines) → `FontFile2` + `CIDFontType2`, with **compact subsetting** (only used glyphs are embedded, including recursive collection of composite-glyph components and glyph-ID renumbering); OpenType/CFF (OTTO) → `FontFile3` + `CIDFontType0C`.
- **Two-pass rendering**: during document construction, text is written as placeholders and the code points used are collected; before `Output()`, subsetting happens once, a code-point→new-glyph-ID map is built, and the content stream is backfilled.
- **ToUnicode**: generated automatically so the PDF text is selectable and searchable (`pdffonts` shows `uni=yes`).
- **Line-break rules**: CJK / fullwidth / Hangul / emoji can break between characters; Latin breaks on spaces.
- **Missing glyphs**: a glyph not present in the font (e.g. an emoji) is shown blank — **no error is raised and other characters are unaffected**.

### 7.4 Font selection advice & known limitations

| Item | Description |
| --- | --- |
| Recommended fonts | **TrueType (glyf)** outline CJK fonts, such as WenQuanYi Micro Hei (`wqy-microhei`) or WenQuanYi Zen Hei. After subsetting these are typically only tens of KB |
| CFF font size | OpenType/CFF fonts (e.g. Noto Sans CJK / Source Han Sans) currently use **whole-font embedding** (no CFF subsetting yet), so a CJK font may reach 10MB+. Functionality is correct, only the size is large; choose a glyf font if size matters |
| TTC collections | Reading is supported, using the 0th font (to use another index, split it out first with tools such as fontTools) |
| Vertical writing | Not supported (Identity-H is horizontal) |
| Complex text shaping | No OpenType shaping (e.g. Arabic ligatures, Indic reordering); Chinese/Latin/emoji scenarios are unaffected |
| Font licensing | Embedding distributes (a subset of) the font into the PDF; confirm the font license permits embedding (WenQuanYi series does) |

---

## 8. Security & Hardening (enhancements in this fixed build)

| Risk | Before fix | After fix |
| --- | --- | --- |
| **Null-byte injection** | A file name/path containing `\0` could be truncated, causing unauthorized read/write | `Image` / `Output('F')` / `AddFont` / `_loadfont` all reject inputs containing `\0` |
| **Fatal error when no font set** | Calling a text method without `SetFont` raised "array index on null" on PHP 7.4+ | Raises a friendly `No font has been set` error |
| **`$_SERVER['HTTP_USER_AGENT']` undefined** | Produced an undefined-index warning under CLI / when the key is absent | Guarded with `isset` |
| **`mbstring.func_overload`** | Removed in PHP 8.0; `ini_get` returning `false` made bitwise ops unsafe | Forced `(int)` cast, safe across all versions |
| **`utf8_encode` deprecated** | Deprecated in PHP 8.2, removed in PHP 9 | Self-implemented Latin-1→UTF-8 (no dependency, no deprecation warning) |
| **Null bytes inside PDF strings** | `_escape` did not handle `\0` and could truncate the PDF, corrupting the file | `_escape` now strips `\0` |
| **`$font['subsetted']` undefined key** | Produced an undefined-key warning for Type0 Chinese fonts | Switched to `!empty()` check |
| **Embedded-font stream compression flag mismatch** | Declared `FlateDecode` but wrote uncompressed data, causing reader errors and a blank page | Now truly `gzcompress`ed per the `compress` flag before writing |

### Security usage advice
- **Do not** let users directly control the file name of `Image()` / `Output('F')` without an allow-list check; even with the `\0` guard, still restrict the write directory and extension.
- Metadata passed to `SetTitle/SetAuthor/...` is written into the PDF info dictionary and is already escaped for `( ) \` (to prevent PDF injection), but it is still advisable to limit its length.
- Core-font metric files (`font/*.php`), if uploaded by users, must be strictly confined to a directory so they cannot be included/executed as PHP (the library already forbids file names containing `/`, `\`, `\0`).
- The font path passed to `AddFontTTF` also comes from server-side configuration — never concatenate user input directly into a font path.

---

## 9. Compatibility & Verification

- The code style keeps `array()` syntax (PHP 5.5 compatible) and uses no features exclusive to PHP 7+.
- All 12 examples were run in full under **PHP 8.3.6** with `E_ALL`: **no Notice / Warning / Deprecation** on stderr.
- Embedded-font PDFs were triple-verified with `pdffonts` / `pdftotext` / PyMuPDF: font is embed-subset (`emb=yes sub=yes uni=yes`), Chinese/English/tables render correctly, and text is selectable.
- Method signatures stay consistent with the parent class (e.g. `MultiCell`'s `$fill` default unified to `false`) to avoid LSP-compatibility issues on PHP 8.

---

## 10. FAQ

**Q1: Why is Chinese rendered as boxes / blank?**
A: If you are using Route B (`AddGBFont`), the reader lacks Adobe Asian fonts — switch to Route A (`AddFontTTF` embedded font). If you are already on Route A, verify the font file path is correct and that the font actually contains the required glyphs.

**Q2: `SetFont('Arial')` errors with "Could not include font definition file"?**
A: Core fonts require the `font/` directory and its 14 metric `.php` files. Using only Chinese (Route A or B) does not depend on that directory.

**Q3: How do I output Chinese from UTF-8 source?**
A: With Route A (`AddFontTTF`) you can pass UTF-8 directly, no transcoding needed. Only if you insist on Route B do you need `iconv('UTF-8','GBK', $s)`.

**Q4: How do I return the PDF as a string instead of outputting it?**
A: `$pdf->Output('S')` returns a string, convenient for caching or saving to a variable.

**Q5: How big is the file after embedding a font?**
A: A TrueType (glyf) font is typically tens of KB after subsetting (grows with the number of glyphs used); an OpenType/CFF font is currently embedded whole and may reach 10MB+. If size matters, choose a glyf font (e.g. WenQuanYi Micro Hei).

**Q6: Can emoji / rare characters be displayed?**
A: It depends on whether the font contains that glyph. Code-point parsing, width calculation, and line breaking all support 1–4 byte UTF-8; when the font lacks a glyph, that character is left blank and no error is raised. For emoji, use a font that includes them.

**Q7: Can the text in the generated PDF be copied / searched?**
A: Yes. Embedded fonts automatically carry a ToUnicode map (`pdffonts` shows `uni=yes`), and copying yields correct UTF-8 text.

---

## 11. Example Index (examples/)

> Also included is the single-file web tool `index.php`: an HTML5+CSS3+AJAX+PHP 7.0 single-page app that uploads a `.md` file or pastes
> Markdown text and, with one click, generates a beautifully laid-out A4 PDF (Route A embedded font) for download. To deploy, place
> `index.php`, `fpdf.php`, `chinese.php`, `ttf.php`, and `assets/wqy-microhei.ttf`
> in the same directory and access it via `php -S localhost:8000` or any PHP 7.0+ environment.

| Example | Content | Font route |
| --- | --- | --- |
| `01_quote.php` | Single-page quotation (table + Logo) | B (GB) |
| `02_markdown.php` | Markdown to multi-page PDF + page numbers | B (GB) |
| `03_certificate.php` | Academic certificate (photo + QR + official seal) | B (GB) |
| `04_catalog.php` | Product catalog (two columns + product images) | B (GB) |
| `05_employment.php` | Employment certificate (letter + signature + seal) | B (GB) |
| `06_contract.php` | Technical service contract (multi-page clauses + signature/seal) | B (GB) |
| `07_reportcard.php` | Student report card (table + chart) | B (GB) |
| `08_invitation.php` | Invitation letter (centered + double-line border) | B (GB) |
| `09_receipt.php` | Receipt / slip (80mm narrow + QR code) | B (GB) |
| `10_report.php` | Quarterly data report (chart + summary table) | B (GB) |
| `11_ttf_utf8.php` | **UTF-8 native + TrueType embedded font intro** | **A** |
| `12_ttf_advanced.php` | **Embedded-font advanced (AddFontFromStream / multiple sizes / bilingual)** | **A** |
| `output/13_md2pdf_sample.pdf` | Sample output of the `index.php` tool (table/code block/quote) | **A** |

Run with: `php examples/01_quote.php`; output goes to `examples/output/`.

---

## 12. Changelog (this build)

### UTF-8 + TrueType embedded fonts (new)
- Added `ttf.php`: a pure-PHP TrueType/OpenType parser and subsetter (TTC support, cmap format 4/12, compact glyf subsetting, recursive composite-glyph collection and renumbering, checksum recomputation)
- `chinese.php` adds `AddFontTTF` / `AddFontFromStream`, native UTF-8 I/O by default
- Identity-H Type0 composite font output: glyf → `FontFile2`+`CIDFontType2`; CFF → `FontFile3`+`CIDFontType0C`
- Auto-generated ToUnicode CMap (text selectable/searchable)
- Two-pass rendering: placeholder + subset-backfill before `Output()`
- `GetStringWidth` / `MultiCell` / `Write` operate per code point (grapheme), supporting 1–4 byte UTF-8; CJK breaks per character, Latin breaks on spaces
- Fixed the "declares FlateDecode but writes uncompressed data" embedded-font bug that caused a blank page
- Fixed the name-table record-offset misalignment that broke PostScript name parsing (always showing `UnknownFont`)
- `/W` width array writes only the actually used glyphs (compact glyf segments / sparse CFF segments, two encodings)
- Added examples `11_ttf_utf8.php`, `12_ttf_advanced.php`

### Compatibility fixes (continuing the previous build)
- Fixed null-byte injection (Image / Output / AddFont / _loadfont)
- Fixed the fatal error when no font was set (GetStringWidth / MultiCell / Write)
- Guarded `$_SERVER['HTTP_USER_AGENT']` (CLI-friendly)
- `(int)` cast for `mbstring.func_overload`
- Self-implemented `_utf8()`, avoiding the deprecated `utf8_encode` and dropping the mbstring dependency
- `_escape` strips null bytes
- `!empty($font['subsetted'])` removes undefined-key warnings
- `chinese.php`: `isset` guards, consistent `$fill=false` signature, `is_int(strpos)`→`!==false`, `$cw` initialization

> See `next.md` in the same directory for the next-step evolution plan.
