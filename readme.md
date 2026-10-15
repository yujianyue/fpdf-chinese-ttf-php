# FPDF 中文扩展库 · 使用说明书

> 版本：FPDF `1.84` + `PDF_Chinese` 中文扩展（兼容性修复版 + UTF-8/TTF 内嵌字体版）
> 适用 PHP：**5.5 ~ 8.3**（已实测 PHP 8.3 下 `E_ALL` 零告警生成 PDF）
> 作者（原始）：Olivier PLATHEY（FPDF）/ yujianyue（中文扩展）
> 维护说明：本《说明书》配套的 `fpdf.php`、`chinese.php`、`ttf.php` 为增强版，详见文末「变更记录」。

---

## 1. 这是什么

本库由三部分组成：

| 文件 | 作用 |
| --- | --- |
| `fpdf.php` | FPDF 核心库（纯 PHP 实现的 PDF 生成器，不依赖任何外部 PDF 组件） |
| `chinese.php` | `PDF_Chinese` 中文扩展，继承 `FPDF`：既保留 GB / Big5（EUC）CID 方案，也新增 **UTF-8 + TrueType/OpenType 内嵌字体** 方案 |
| `ttf.php` | `TTFontSubsetter`：纯 PHP 的 TrueType/OpenType 解析与子集化器，供内嵌字体使用 |

提供**两条中文输出路线**，可按需选择：

| 路线 | 编码 | 是否内嵌字体 | 体积 | 阅读端要求 | 适用场景 |
| --- | --- | --- | --- | --- | --- |
| **A. 内嵌 TrueType（推荐）** | **UTF-8 原生** | ✅ 子集化内嵌 | 小（几十 KB） | 无任何要求 | 通用、跨平台、要求显示一致 |
| B. CID 引用（GB/Big5） | GBK / Big5 | ❌ 不内嵌 | 极小 | 需 Adobe 亚洲字体 | 极致体积、可控阅读环境 |

> 路线 A 是本版的重点升级：**不再依赖阅读器字体、中英文一致、UTF-8 原生**，详见第 7 节。

---

## 2. 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | **≥ 5.5** | 已验证 5.5 / 7.0 / 7.4 / 8.0 / 8.1 / 8.2 / 8.3 |
| zlib | 可选 | 开启后自动压缩 PDF 内容与内嵌字体流（`SetCompression(true)`，默认开启） |
| GD | 可选 | 仅在使用 `Image()` 嵌入 **GIF** 时需要；JPEG/PNG 不需要 |
| 字体文件 | 路线 A 需要 | 一个 `.ttf` / `.otf` / `.ttc` 字体文件（含中文字形，如文泉驿微米黑） |
| 核心字体目录 `font/` | 使用 Arial/Helvetica/Times/Courier 等**核心字体时需要** | 需放置官方 FPDF 的 14 个核心字体度量文件 |

> 路线 A（`AddFontTTF`）与路线 B（`AddGBFont`）都**不需要** `font/` 目录。
> `ttf.php` 为纯 PHP 实现，不依赖 `mbstring`、`intl` 等扩展。

---

## 3. 安装与目录结构

将三个文件放在项目中，`require` 即可（`chinese.php` 会自动加载 `ttf.php`）：

```php
require('fpdf.php');
require('chinese.php');
```

推荐目录结构：

```
your_project/
├── fpdf.php
├── chinese.php
├── ttf.php
├── fonts/                 # 你自己的字体文件（.ttf/.otf/.ttc）
│   └── wqy-microhei.ttf
└── font/                  # 仅使用核心字体时需要
    └── ...
```

---

## 4. 快速开始

### 4.1 中文 PDF（内嵌 TrueType，UTF-8 原生 · 推荐）

```php
<?php
require('fpdf.php');
require('chinese.php');

$pdf = new PDF_Chinese();
$pdf->AddFontTTF('wqy', '', 'fonts/wqy-microhei.ttf');   // 注册内嵌字体
$pdf->AddPage();
$pdf->SetFont('wqy', '', 20);
$pdf->Cell(0, 12, '中文 UTF-8 原生输入，无需 iconv');      // 直接传 UTF-8
$pdf->Ln(16);
$pdf->SetFont('wqy', '', 12);
$pdf->MultiCell(0, 8, '中英混排 Mixed text 自动换行，宽度按码点精确计算。');
$pdf->Output('F', 'demo.pdf');
```

生成结果可用 `pdffonts demo.pdf` 验证：`emb=yes sub=yes uni=yes`（已子集化内嵌 + 带 ToUnicode 映射，文字可复制检索）。

### 4.2 中文 PDF（CID 引用，GBK 编码 · 旧方案）

```php
<?php
require('fpdf.php');
require('chinese.php');

$pdf = new PDF_Chinese();
$pdf->AddGBFont();                        // 注册 GB 中文字体（无需字体文件）
$pdf->AddPage();
$pdf->SetFont('GB', '', 20);
$pdf->Cell(0, 12, iconv('UTF-8', 'GBK', '中文测试标题'));
$pdf->Output('D', 'demo.pdf');
```

### 4.3 从内存字节注册字体

```php
$bytes = file_get_contents('fonts/wqy-microhei.ttf');    // 或从数据库/远端读取
$pdf->AddFontFromStream('wqy', '', $bytes);
$pdf->SetFont('wqy', '', 14);
```

---

## 5. 核心 API 参考（FPDF 公共方法）

> 以下为常用方法；完整方法以 `fpdf.php` 源码为准。参数默认值与官方 FPDF 1.84 保持一致。

| 方法 | 说明 |
| --- | --- |
| `AddPage($orientation='', $size='', $rotation=0)` | 新增一页，可选方向(L/P)、尺寸(A3/A4/A5/Letter…)、旋转角度 |
| `SetFont($family, $style='', $size=0)` | 设置字体。核心字体：`Arial/Helvetica/...`；内嵌字体：`AddFontTTF` 注册的 family；CID 中文：`GB`/`Big5` |
| `SetFontSize($size)` | 仅改字号 |
| `Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')` | 输出一个单元格（单行文本） |
| `MultiCell($w, $h, $txt, $border=0, $align='L', $fill=false)` | 多行文本自动换行（内嵌字体与中文版均已重写断行） |
| `Write($h, $txt, $link='')` | 流式输出，自动换行（同上） |
| `Ln($h=null)` | 换行 |
| `SetXY($x, $y)` / `SetX` / `SetY` | 设置光标坐标 |
| `Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')` | 插入图片（JPEG/PNG/GIF） |
| `Line($x1,$y1,$x2,$y2)` / `Rect(...)` | 画线/矩形 |
| `SetMargins($left,$top,$right=null)` | 页边距 |
| `SetAutoPageBreak($auto, $margin=0)` | 自动分页 |
| `SetTitle/SetAuthor/SetSubject/SetKeywords/SetCreator($v, $isUTF8=false)` | 文档元数据 |
| `AliasNbPages($alias='{nb}')` | 总页数占位符（页脚 `{nb}` 用） |
| `Output($dest='', $name='', $isUTF8=false)` | 输出。`I` 内联，`D` 下载，`F` 存文件，`S` 返回字符串 |
| `Error($msg)` | 抛出 FPDF 错误（会终止脚本） |

坐标单位为 **毫米（mm）**，原点在页面左上角。

---

## 6. 中文专属 API（PDF_Chinese · 路线 B）

| 方法 | 说明 |
| --- | --- |
| `AddGBFont($family='GB', $name='STSongStd-Light-Acro')` | 注册简体中文（GB）比例宽度字体 |
| `AddGBhwFont($family='GB-hw', ...)` | 注册简体中文半角拉丁字体 |
| `AddBig5Font($family='Big5', $name='MSungStd-Light-Acro')` | 注册繁体中文（Big5）字体 |
| `AddBig5hwFont($family='Big5-hw', ...)` | 注册繁体中文半角拉丁字体 |
| `GetMBStringWidth($s)` | 多字节字符串宽度（内部使用） |
| `MBMultiCell(...)` / `MBWrite(...)` | 多字节版 `MultiCell` / `Write`（内部使用） |

---

## 7. UTF-8 + TrueType/OpenType 内嵌字体（路线 A · 本版核心新增）

### 7.1 解决了什么

旧方案（路线 B）的三大痛点，在本方案中全部消除：

| 痛点（旧） | 本方案 |
| --- | --- |
| 依赖阅读端 Adobe 亚洲字体，缺字体时出方块 | 字体**子集化后内嵌**进 PDF，任何阅读器/设备显示一致 |
| UTF-8 源码必须先 `iconv('UTF-8','GBK',...)` | **UTF-8 原生输入输出**，直接传字符串 |
| 断行按"非 ASCII 即 2 字节"假设，混排不精确 | 按**码点（grapheme）**计算宽度与断行，支持 1–4 字节（含 emoji 码点） |

### 7.2 API

| 方法 | 说明 |
| --- | --- |
| `AddFontTTF($family, $style='', $file='', $subset=true)` | 从字体文件注册（支持 `.ttf` / `.otf` / `.ttc`，TTC 取第 0 个字体） |
| `AddFontFromStream($family, $style='', $data='', $subset=true)` | 从内存字节注册（数据库 / 远端拉取的字体） |

注册后用 `SetFont($family, $style, $size)` 即可，`Cell` / `MultiCell` / `Write` / `GetStringWidth` 全部自动走 UTF-8 路径，调用方式与内置字体完全一致。

### 7.3 内部实现要点（了解即可，不影响使用）

- **编码**：`Identity-H` 的 Type0 复合字体；文本以"字形 ID 的 2 字节大端"写入内容流。
- **内嵌**：TrueType（glyf 轮廓）→ `FontFile2` + `CIDFontType2`，**紧凑子集化**（只嵌入用到的字形，含复合字形组件递归收集与 gid 重编号）；OpenType/CFF（OTTO）→ `FontFile3` + `CIDFontType0C`。
- **两遍渲染**：文档构建期文本先写成占位符并收集用到的码点；`Output()` 前统一子集化、建立码点→新字形 ID 映射并回填内容流。
- **ToUnicode**：自动生成，保证 PDF 里的文字可复制、可检索（`pdffonts` 显示 `uni=yes`）。
- **断行规则**：CJK / 全角 / 谚文 / emoji 逐字可断；Latin 按空格断。
- **缺字行为**：字体没有的字形（如 emoji）留空显示，**不会报错、不影响其他字符**。

### 7.4 字体选择建议与已知限制

| 项 | 说明 |
| --- | --- |
| 推荐字体 | **TrueType（glyf）轮廓**的中文字体，如文泉驿微米黑（`wqy-microhei`）、文泉驿正黑。子集化后通常仅几十 KB |
| CFF 字体体积 | OpenType/CFF（如 Noto Sans CJK / 思源黑体）当前采用**整字体嵌入**（未做 CFF 子集化），中文字体可能达 10MB+。功能正确，仅体积大；在意体积请选 glyf 字体 |
| TTC 集合 | 支持读取，取第 0 个字体（如需其他索引可先用 fontTools 等工具拆出） |
| 竖排文字 | 不支持（Identity-H 横排） |
| 复杂文本整形 | 不做 OpenType shaping（如阿拉伯语连字、_indic_ 重排）；中文/西文/emoji 场景不受影响 |
| 字体授权 | 内嵌会将字体（子集）分发进 PDF，请确认所用字体许可允许嵌入（文泉驿系列允许） |

---

## 8. 安全与加固（本修复版增强）

| 风险点 | 修复前 | 修复后 |
| --- | --- | --- |
| **null 字节注入** | 文件名/路径含 `\0` 可能被截断，造成越权读取/写入 | `Image` / `Output('F')` / `AddFont` / `_loadfont` 均拦截含 `\0` 的输入 |
| **未设字体致命错误** | 未 `SetFont` 就调用文本方法，PHP 7.4+ 报"对 null 取数组下标" | 抛出友好 `No font has been set` 错误 |
| **`$_SERVER['HTTP_USER_AGENT']` 未定义** | CLI / 无该键时产生未定义索引告警 | 已做 `isset` 守卫 |
| **`mbstring.func_overload`** | PHP 8.0 已移除该 ini，`ini_get` 返回 `false` 直接位运算有隐患 | 强制 `(int)` 转换，全版本安全 |
| **`utf8_encode` 弃用** | PHP 8.2 弃用、PHP 9 移除 | 自实现 Latin-1→UTF-8（无依赖、无弃用告警） |
| **PDF 字符串中的 null 字节** | `_escape` 不处理 `\0`，可能截断 PDF 导致文件损坏 | `_escape` 主动剔除 `\0` |
| **`$font['subsetted']` 未定义键** | 对 Type0 中文字体产生未定义键告警 | 改用 `!empty()` 判断 |
| **内嵌字体流压缩标记不一致** | 声明 `FlateDecode` 但写入未压缩数据，阅读器报错、整页空白 | 按 `compress` 开关真正 `gzcompress` 后写入 |

### 使用建议（安全）
- **不要**让用户直接控制 `Image()`、`Output('F')` 的文件名而不做白名单校验；即便有 `\0` 拦截，仍应限制写入目录与扩展名。
- 传入 `SetTitle/SetAuthor/...` 的元数据会写入 PDF 信息字典，已做 `( ) \` 转义（防 PDF 注入），但建议仍对长度做限制。
- 核心字体度量文件（`font/*.php`）若由用户上传，须严格限制目录，避免被当作 PHP 文件包含执行（库已禁止文件名含 `/`、`\`、`\0`）。
- `AddFontTTF` 的字体路径同样来自服务端配置，不要把用户输入直接拼进字体路径。

---

## 9. 兼容性与验证

- 代码风格保持 `array()` 语法（兼容 PHP 5.5），未使用 PHP 7+ 才有的特性。
- 已在 **PHP 8.3.6** 下以 `E_ALL` 完整运行 12 个示例：**stderr 无任何 Notice / Warning / Deprecation**。
- 内嵌字体 PDF 已用 `pdffonts` / `pdftotext` / PyMuPDF 渲染三重验证：字体已内嵌子集（`emb=yes sub=yes uni=yes`）、中文/英文/表格渲染正确、文字可复制。
- 方法签名保持与父类一致（如 `MultiCell` 的 `$fill` 默认值统一为 `false`），避免 PHP 8 的 LSP 兼容问题。

---

## 10. 常见问题（FAQ）

**Q1：为什么中文显示成方块/空白？**
A：若用的是路线 B（`AddGBFont`），说明阅读器缺少 Adobe 亚洲字体，请改用路线 A（`AddFontTTF` 内嵌字体）；若已是路线 A，请确认字体文件路径正确且字体确实包含所需字形。

**Q2：`SetFont('Arial')` 报错 "Could not include font definition file"？**
A：使用核心字体需要 `font/` 目录及其中的 14 个度量 `.php` 文件。仅用中文（路线 A 或 B）可不依赖该目录。

**Q3：UTF-8 源码怎么输出中文？**
A：用路线 A（`AddFontTTF`）即可直接传 UTF-8，无需任何转码。若坚持用路线 B，才需要 `iconv('UTF-8','GBK', $s)`。

**Q4：如何直接返回 PDF 字符串而非输出？**
A：`$pdf->Output('S')` 返回字符串，便于保存到变量或做缓存。

**Q5：内嵌字体后文件有多大？**
A：TrueType（glyf）字体子集化后一般几十 KB（随用到的字形数增长）；OpenType/CFF 字体当前整字体嵌入，可能达 10MB+，在意体积请选 glyf 字体（如文泉驿微米黑）。

**Q6：emoji / 生僻字能显示吗？**
A：取决于字体是否包含该字形。码点解析、宽度计算、断行对 1–4 字节 UTF-8 全部支持；字体缺字形时该字符留空，不会报错。需要 emoji 请换用含 emoji 的字体。

**Q7：生成的 PDF 里文字能复制/搜索吗？**
A：能。内嵌字体自动带 ToUnicode 映射（`pdffonts` 显示 `uni=yes`），复制出来是正确的 UTF-8 文本。

---

## 11. 示例索引（examples/）

> 另附单文件 Web 工具 `index.php`：HTML5+CSS3+AJAX+PHP 7.0 单页应用，上传 `.md` 或粘贴
> Markdown 文本，一键生成精美 A4 排版 PDF（路线 A 内嵌字体）并下载。部署时将
> `index.php`、`fpdf.php`、`chinese.php`、`ttf.php` 与 `assets/wqy-microhei.ttf`
> 放在同一目录，用 `php -S localhost:8000` 或任意 PHP 7.0+ 环境访问即可。

| 示例 | 内容 | 字体路线 |
| --- | --- | --- |
| `01_quote.php` | 单页报价单（表格 + Logo） | B（GB） |
| `02_markdown.php` | Markdown 转多页 PDF + 页码 | B（GB） |
| `03_certificate.php` | 学历证明（证件照 + 二维码 + 公章） | B（GB） |
| `04_catalog.php` | 产品目录（双栏 + 产品图） | B（GB） |
| `05_employment.php` | 在职证明（信函 + 签名 + 公章） | B（GB） |
| `06_contract.php` | 技术服务合同（多页条款 + 签章） | B（GB） |
| `07_reportcard.php` | 学生成绩单（表格 + 图表） | B（GB） |
| `08_invitation.php` | 邀请函（居中 + 双线边框） | B（GB） |
| `09_receipt.php` | 收据/小票（80mm 窄幅 + 二维码） | B（GB） |
| `10_report.php` | 季度数据报表（图表 + 汇总表） | B（GB） |
| `11_ttf_utf8.php` | **UTF-8 原生 + TrueType 内嵌字体入门** | **A** |
| `12_ttf_advanced.php` | **内嵌字体进阶（AddFontFromStream / 多字号 / 双语）** | **A** |
| `output/13_md2pdf_sample.pdf` | `index.php` 工具的输出样例（含表格/代码块/引用） | **A** |

运行方式：`php examples/01_quote.php`，输出在 `examples/output/`。

---

## 12. 变更记录（本版）

### UTF-8 + TrueType 内嵌字体（新增）
- 新增 `ttf.php`：纯 PHP TrueType/OpenType 解析与子集化器（TTC 支持、cmap format 4/12、glyf 紧凑子集、复合字形递归收集与重编号、校验和重算）
- `chinese.php` 新增 `AddFontTTF` / `AddFontFromStream`，默认 UTF-8 原生输入输出
- Identity-H Type0 复合字体输出：glyf → `FontFile2`+`CIDFontType2`；CFF → `FontFile3`+`CIDFontType0C`
- 自动生成 ToUnicode CMap（文字可复制检索）
- 两遍渲染：占位符 + `Output()` 前子集化回填
- `GetStringWidth` / `MultiCell` / `Write` 按码点（grapheme）处理，支持 1–4 字节 UTF-8；CJK 逐字断行、Latin 按空格断行
- 修复内嵌字体流"声明 FlateDecode 但未压缩"导致整页空白的问题
- 修复 name 表记录偏移错位导致 PostScript 名解析失败（一直显示 `UnknownFont`）的问题
- `/W` 宽度数组只写入实际用到的字形（glyf 紧凑段 / CFF 稀疏段两种编码）
- 新增示例 `11_ttf_utf8.php`、`12_ttf_advanced.php`

### 兼容性修复（延续上一版）
- 修复 null 字节注入（Image / Output / AddFont / _loadfont）
- 修复未设字体时的致命错误（GetStringWidth / MultiCell / Write）
- 守卫 `$_SERVER['HTTP_USER_AGENT']`（CLI 友好）
- `(int)` 强转 `mbstring.func_overload`
- 自实现 `_utf8()`，规避 `utf8_encode` 弃用，且不再依赖 mbstring
- `_escape` 剔除 null 字节
- `!empty($font['subsetted'])` 消除未定义键告警
- `chinese.php`：`isset` 守卫、`$fill=false` 签名一致、`is_int(strpos)`→`!==false`、`$cw` 初始化

> 下一步演进计划见同目录 `next.md`。
