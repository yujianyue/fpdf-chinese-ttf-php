<?php
/**
 * ============================================================================
 * index.php —— Markdown 转精美 PDF（A4）单文件工具
 * ----------------------------------------------------------------------------
 * 技术栈：HTML5 + CSS3 + AJAX(fetch) + PHP 7.0（兼容 7.0~8.3）
 * 依赖  ：同目录下 fpdf.php / chinese.php / ttf.php（FPDF 中文扩展）
 *         字体   assets/wqy-microhei.ttf（内嵌子集，阅读端无需任何字体）
 *
 * 功能：
 *   1) 上传 .md 文件，或直接在页面粘贴 Markdown 文本；
 *   2) AJAX 提交到本文件（?action=convert），后端解析 Markdown 并排版为
 *      A4 精美 PDF（标题/列表/表格/代码块/引用/链接/分隔线，页脚页码）；
 *   3) 前端自动触发下载，并支持浏览器内预览。
 *
 * 安全：
 *   - 仅接受 md / markdown / txt 扩展名，文本上限 2MB；
 *   - 输出文件名经过严格清洗，路径常量均基于 __DIR__。
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');            // 生产环境不向浏览器泄漏错误细节
ini_set('memory_limit', '512M');           // 字体解析与子集化需要一定内存
@set_time_limit(120);

define('MAX_TEXT_BYTES', 2 * 1024 * 1024); // 2MB

// 全程输出缓冲：一方面避免任何意外输出破坏 JSON/PDF 响应，
// 另一方面让致命错误的兜底 handler 可以丢弃残留输出、返回可读的 JSON
ob_start();

/**
 * 致命错误兜底：内存耗尽 / 超时 / include 失败等不可捕获错误发生时，
 * 保证 AJAX 端拿到的是结构化 JSON 而不是空白响应（否则前端会报
 * "Failed to execute 'json' on 'Response': Unexpected end of JSON input"）。
 */
function md2pdf_fatal_guard()
{
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $msg = isset($e['message']) ? $e['message'] : '未知错误';
        $line = isset($e['line']) ? $e['line'] : 0;
        echo json_encode(array(
            'ok' => false,
            'error' => '服务器内部错误（' . $msg . '，第 ' . $line . ' 行）。'
                     . '常见原因：内存不足（调大 memory_limit）、缺少依赖文件或字体文件。',
        ));
    }
}

/* ---------------------------------------------------------------------------
 * 字体查找：可按部署位置调整，也可直接改 $FONT 指定绝对路径
 * ------------------------------------------------------------------------- */
$FONT_CANDIDATES = array(
    __DIR__ . '/assets/wqy-microhei.ttf',
    __DIR__ . '/examples/assets/wqy-microhei.ttf',
    __DIR__ . '/font/wqy-microhei.ttf',
    __DIR__ . '/wqy-microhei.ttf',
);
$FONT = '';
foreach ($FONT_CANDIDATES as $f) {
    if (is_readable($f)) { $FONT = $f; break; }
}

/* ---------------------------------------------------------------------------
 * 依赖检查：仅转换接口需要 FPDF 扩展；缺失时页面仍可打开，
 * 接口会返回明确的 JSON 错误（而不是致命错误的空白响应）。
 * ------------------------------------------------------------------------- */
$DEPS_MISSING = array();
foreach (array('fpdf.php', 'chinese.php', 'ttf.php') as $dep) {
    if (!is_readable(__DIR__ . '/' . $dep)) $DEPS_MISSING[] = $dep;
}
if (empty($DEPS_MISSING)) {
    require_once __DIR__ . '/fpdf.php';
    require_once __DIR__ . '/chinese.php';   // chinese.php 内部会加载 ttf.php
}

/* ===========================================================================
 * PDF 文档类：A4 + 页脚（左侧文档标题 / 右侧页码）
 * 注：内嵌字体走 Identity-H，文本按码点回填为字形，AliasNbPages 的 {nb}
 *     占位在 TTF 模式下会随文本一起被编码，因此这里直接用 PageNo()。
 * 仅在依赖加载成功后声明，避免缺文件时页面本身也致命错误。
 * =========================================================================== */
if (class_exists('PDF_Chinese', false))
{
class MD_PDF extends PDF_Chinese
{
    public $docTitle = 'Markdown 文档';

    // FPDF 的边距属性为 protected，这里暴露只读访问器
    function getLM(){ return $this->lMargin; }
    function getTM(){ return $this->tMargin; }
    function getRM(){ return $this->rMargin; }

    function Footer()
    {
        $this->SetY(-13);
        $this->SetFont('wqy', '', 8.5);
        $this->SetTextColor(150, 155, 165);
        $this->Cell(0, 6, $this->docTitle, 0, 0, 'L');
        $this->SetX($this->lMargin);
        $this->Cell(0, 6, '第 ' . $this->PageNo() . ' 页', 0, 0, 'R');
        $this->SetTextColor(30, 34, 40);
    }
}
} // end class_exists('PDF_Chinese')

/* ===========================================================================
 * Markdown → PDF 渲染器
 * =========================================================================== */
class MD_Renderer
{
    protected $pdf;
    protected $lm = 16;    // 左边距 mm
    protected $rm;         // 右边界 mm
    protected $accent = array(11, 107, 203);      // 主题蓝
    protected $codeFg  = array(180, 54, 27);      // 行内代码色
    protected $link    = array(11, 107, 203);     // 链接色
    protected $quoteFg = array(95, 105, 115);     // 引用文字色
    public $title = '';

    function __construct(MD_PDF $pdf)
    {
        $this->pdf = $pdf;
        $this->rm  = $pdf->GetPageWidth() - $this->lm;
    }

    function render($md)
    {
        $pdf = $this->pdf;
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        // SetMargins(左, 上, 右)：右边距传"页宽 - 右边界"的差值
        $pdf->SetMargins($this->lm, 14, $pdf->GetPageWidth() - $this->rm);

        $lines = explode("\n", str_replace("\r", '', $md));
        $n = count($lines);
        $inCode = false;
        $codeBuf = array();
        $i = 0;

        while ($i < $n) {
            $ln = $lines[$i];

            /* ---------- 代码块 ---------- */
            if ($inCode) {
                if (preg_match('/^\s*```/', $ln)) {
                    $this->flushCode($codeBuf);
                    $codeBuf = array();
                    $inCode = false;
                } else {
                    $codeBuf[] = $ln;
                }
                $i++;
                continue;
            }
            if (preg_match('/^(\s*)(`{3,}|~{3,})\s*(\S*)\s*$/', $ln)) {
                $inCode = true;
                $i++;
                continue;
            }

            /* ---------- 表格 ---------- */
            if (preg_match('/^\s*\|.*\|\s*$/', $ln) && $i + 1 < $n
                && preg_match('/^\s*\|[\s:|-]+\|\s*$/', $lines[$i + 1])) {
                $rows = array();
                $head = $this->splitRow($ln);
                $i += 2;
                while ($i < $n && preg_match('/^\s*\|.*\|\s*$/', $lines[$i])) {
                    $rows[] = $this->splitRow($lines[$i]);
                    $i++;
                }
                $this->drawTable($head, $rows);
                continue;
            }

            $t = trim($ln);

            /* ---------- 空行 ---------- */
            if ($t === '') { $pdf->Ln(2.2); $i++; continue; }

            /* ---------- 分隔线 ---------- */
            if (preg_match('/^(\*[\s]*){3,}$|^(-[\s]*){3,}$|^(_[\s]*){3,}$/', $ln)) {
                $pdf->Ln(1.5);
                $y = $pdf->GetY();
                $pdf->SetDrawColor(215, 222, 230);
                $pdf->SetLineWidth(0.4);
                $pdf->Line($this->lm, $y, $this->rm, $y);
                $pdf->Ln(4.5);
                $i++;
                continue;
            }

            /* ---------- 标题 ---------- */
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $ln, $m)) {
                $this->drawHeading(strlen($m[1]), $m[2]);
                $i++;
                continue;
            }

            /* ---------- 引用块（连续 > 行） ---------- */
            if (preg_match('/^\s*>\s?(.*)$/', $ln, $m)) {
                $quote = array($m[1]);
                $i++;
                while ($i < $n && preg_match('/^\s*>\s?(.*)$/', $lines[$i], $mm)) {
                    $quote[] = $mm[1];
                    $i++;
                }
                $this->drawQuote($quote);
                continue;
            }

            /* ---------- 无序列表（含连续项） ---------- */
            if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $ln, $m)) {
                while ($i < $n && preg_match('/^(\s*)[-*+]\s+(.*)$/', $lines[$i], $mm)) {
                    $this->drawBullet('•', $mm[2]);
                    $i++;
                }
                $pdf->Ln(1.2);
                continue;
            }

            /* ---------- 有序列表 ---------- */
            if (preg_match('/^(\s*)(\d+)[.)]\s+(.*)$/', $ln, $m)) {
                while ($i < $n && preg_match('/^(\s*)(\d+)[.)]\s+(.*)$/', $lines[$i], $mm)) {
                    $this->drawBullet($mm[2] . '.', $mm[3]);
                    $i++;
                }
                $pdf->Ln(1.2);
                continue;
            }

            /* ---------- 普通段落（合并软换行） ---------- */
            $para = array($ln);
            $i++;
            while ($i < $n && trim($lines[$i]) !== ''
                   && !preg_match('/^\s*(?:#{1,6}\s|>|\|[-\s:|]*\||[-*+]\s|\d+[.)]\s|(`{3,}|~{3,}))/', $lines[$i])
                   && !preg_match('/^(\*[\s]*){3,}$|^(-[\s]*){3,}$/', $lines[$i])) {
                $para[] = preg_replace('/^\s+/', '', $lines[$i]);
                $i++;
            }
            $this->drawParagraph(implode(' ', $para));
        }

        if ($inCode) $this->flushCode($codeBuf);   // 未闭合代码块兜底
    }

    /* ---------------- 各类块级元素 ---------------- */

    protected function drawHeading($level, $text)
    {
        $pdf = $this->pdf;
        $text = trim($text);
        if ($text === '') return;
        if ($level == 1 && $this->title === '') $this->title = $text;

        $pdf->Ln($level == 1 ? 3 : 2.5);
        $y = $pdf->GetY();

        if ($level == 1) {
            $pdf->SetTextColor(16, 42, 82);
            $pdf->SetFont('wqy', '', 19);
            $pdf->MultiCell(0, 9.5, $text, 0, 'L');
            $pdf->SetDrawColor($this->accent[0], $this->accent[1], $this->accent[2]);
            $pdf->SetLineWidth(0.8);
            $pdf->Line($this->lm, $pdf->GetY() + 1.2, $this->lm + 42, $pdf->GetY() + 1.2);
            $pdf->SetDrawColor(222, 230, 240);
            $pdf->SetLineWidth(0.4);
            $pdf->Line($this->lm + 42, $pdf->GetY() + 1.2, $this->rm, $pdf->GetY() + 1.2);
            $pdf->Ln(6.5);
        } elseif ($level == 2) {
            $pdf->SetFillColor($this->accent[0], $this->accent[1], $this->accent[2]);
            $pdf->Rect($this->lm, $y + 0.6, 1.7, 6.2, 'F');
            $pdf->SetTextColor(16, 42, 82);
            $pdf->SetFont('wqy', '', 14.5);
            $pdf->SetX($this->lm + 4.5);
            $pdf->MultiCell(0, 7.4, $text, 0, 'L');
            $pdf->Ln(2.2);
        } elseif ($level == 3) {
            $pdf->SetTextColor(28, 60, 100);
            $pdf->SetFont('wqy', '', 12.5);
            $pdf->MultiCell(0, 6.8, $text, 0, 'L');
            $pdf->Ln(1.2);
        } else {
            $pdf->SetTextColor(45, 62, 84);
            $pdf->SetFont('wqy', '', 11);
            $pdf->MultiCell(0, 6.2, $text, 0, 'L');
            $pdf->Ln(0.8);
        }
        $pdf->SetTextColor(30, 34, 40);
    }

    protected function drawParagraph($text)
    {
        $this->richFlow($text, 10.8, 6.1);
        $this->pdf->Ln(1.6);
    }

    protected function drawBullet($marker, $text)
    {
        $pdf = $this->pdf;
        $lm = $pdf->getLM();
        $pdf->SetTextColor($this->accent[0], $this->accent[1], $this->accent[2]);
        $pdf->SetFont('wqy', '', 10.8);
        $pdf->Cell(7, 6.1, $marker, 0, 0, 'L');
        $pdf->SetTextColor(30, 34, 40);
        $pdf->SetMargins($lm + 7, $pdf->getTM(), $pdf->getRM());
        $this->richFlowRaw($text, 10.8, 6.1);
        $pdf->SetMargins($lm, $pdf->getTM(), $pdf->getRM());
        $pdf->Ln(6.1);   // 恢复左边距后再换行，保证下一项从行首开始
    }

    protected function drawQuote($lines)
    {
        $pdf = $this->pdf;
        $pdf->Ln(1);
        $y0 = $pdf->GetY();
        $lm = $pdf->getLM();
        $pdf->SetMargins($lm + 6, $pdf->getTM(), $pdf->getRM());
        $pdf->SetTextColor(95, 105, 115);
        foreach ($lines as $q) {
            if (trim($q) === '') { $pdf->Ln(2); continue; }
            // Write 沿用当前 x，首行需显式缩进到竖条右侧
            $pdf->SetX($this->lm + 6);
            $this->richFlowRaw($q, 10.4, 5.9);
            $pdf->Ln(5.9);
        }
        $pdf->SetMargins($lm, $pdf->getTM(), $pdf->getRM());
        // 左侧竖条（跨页时 y0 会被自动分页重置到页顶，取当前页内可见范围）
        $y1 = $pdf->GetY();
        if ($y1 < $y0) $y0 = $pdf->getTM();
        $pdf->SetFillColor(190, 205, 220);
        $pdf->Rect($this->lm, $y0, 1.4, max(4, $y1 - $y0), 'F');
        $pdf->SetTextColor(30, 34, 40);
        $pdf->Ln(2);
    }

    protected function flushCode($buf)
    {
        if (empty($buf)) return;
        $pdf = $this->pdf;
        $pdf->Ln(2);
        $y0 = $pdf->GetY();
        $inset = 3;        // 代码文本相对底色框的左右内边距 mm
        $lh    = 6.4;      // 代码行距
        $bw    = $this->rm - $this->lm;          // 底色带宽（满宽）
        $tw    = $bw - $inset * 2;               // 文本宽
        $pdf->SetFillColor(243, 245, 248);
        // 临时加左边距，使 MultiCell 的换行续行也保持缩进（而非顶到页边距）
        $lm0 = $pdf->getLM();
        $pdf->SetMargins($this->lm + $inset, $pdf->getTM(), $pdf->GetPageWidth() - ($this->rm - $inset));
        foreach ($buf as $c) {
            $pdf->SetFont('wqy', '', 10.2);
            $pdf->SetTextColor(40, 52, 70);
            $line = ($c === '') ? ' ' : str_replace("\t", '    ', $c);
            // 比例字体的空格较窄，行首缩进双倍呈现，视觉上接近等宽效果
            $line = preg_replace('/^( +)/', '$1$1', $line);
            // 先画预估高度的满宽底色带，再在其上层写文本
            $ly = $pdf->GetY();
            $pdf->Rect($this->lm, $ly, $bw, $this->nbLines($tw, $line) * $lh, 'F');
            $pdf->SetX($this->lm + $inset);
            $pdf->MultiCell($tw, $lh, $line, 0, 'L', false);
        }
        $pdf->SetMargins($lm0, $pdf->getTM(), $pdf->GetPageWidth() - $this->rm);
        $pdf->SetX($this->lm);
        $y1 = $pdf->GetY();
        $pdf->SetDrawColor(220, 226, 234);
        $pdf->SetLineWidth(0.35);
        if ($y1 > $y0) $pdf->Rect($this->lm, $y0, $bw, $y1 - $y0);
        $pdf->SetTextColor(30, 34, 40);
        $pdf->Ln(3.5);
    }

    protected function splitRow($ln)
    {
        $ln = trim($ln);
        // 去掉表格行首尾的边框竖线（开头/结尾各允许一个 | 作为边界）
        $ln = preg_replace('/^\s*\|/', '', $ln, 1);
        $ln = preg_replace('/\|?\s*$/', '', $ln, 1);
        // 仅在「未被转义」的 | 处切分：单元格内的 \| 视为字面竖线，不拆列
        $raw = preg_split('/(?<!\\\\)\|/', $ln);
        $cells = array();
        foreach ($raw as $c) {
            // 还原被转义的字面竖线
            $cells[] = trim(str_replace('\\|', '|', $c));
        }
        return $cells;
    }

    // 纯 PHP UTF-8 切分为字符数组（不依赖 mbstring——该扩展并非默认内置）
    protected function utf8Chars($s)
    {
        $out = array();
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $o = ord($s[$i]);
            if ($o < 0x80)          { $out[] = $s[$i]; $i++; }
            elseif (($o & 0xE0) === 0xC0) { $out[] = substr($s, $i, 2); $i += 2; }
            elseif (($o & 0xF0) === 0xE0) { $out[] = substr($s, $i, 3); $i += 3; }
            elseif (($o & 0xF8) === 0xF0) { $out[] = substr($s, $i, 4); $i += 4; }
            else                    { $out[] = $s[$i]; $i++; }
        }
        return $out;
    }

    // 估算文本在指定宽度内换行后的行数（用于计算表格行高）
    // 取两种估算的较大值：逐字累计（对 CJK / 长 URL 精确）与按空格分词贪心
    //（对英文单词换行精确），避免行高偏小导致文字溢出单元格。
    protected function nbLines($w, $txt)
    {
        $txt = trim($txt);
        if ($txt === '') return 1;
        $pdf = $this->pdf;
        // MultiCell 内部会再减去左右 cMargin（合计约 2mm），这里预先补偿，
        // 否则估算行数偏小、表头/单元格文字会被裁掉
        $w = max(4, $w - 2.2);

        // 估算 1：逐字累计（纯 PHP UTF-8 切分，无 mbstring 依赖）
        $n1 = 1; $lw = 0.0;
        foreach ($this->utf8Chars($txt) as $ch) {
            $cw = $pdf->GetStringWidth($ch);
            $lw += $cw;
            if ($lw > $w) { $n1++; $lw = $cw; }
        }

        // 估算 2：按空格分词贪心
        $spw = $pdf->GetStringWidth(' ');
        $n2 = 1; $lw = 0.0;
        foreach (preg_split('/ +/', $txt) as $word) {
            if ($word === '') continue;
            $ww = $pdf->GetStringWidth($word);
            if ($lw == 0.0) {
                $lw = $ww;
            } elseif ($lw + $spw + $ww <= $w) {
                $lw += $spw + $ww;
            } else {
                $n2++; $lw = $ww;
            }
            while ($lw > $w) { $n2++; $lw -= $w; }   // 单词超宽时按字符强制断
        }
        return max($n1, $n2);
    }

    // 去除行内 Markdown 标记（表格单元格使用纯文本渲染）
    protected function plainText($s)
    {
        $s = preg_replace('/\*\*([^*]+)\*\*|__([^_]+)__/', '$1$2', $s);
        $s = preg_replace('/`([^`]+)`/', '$1', $s);
        $s = preg_replace('/\[([^\]]+)\]\([^)\s]+\)/', '$1', $s);
        return $s;
    }

    // 绘制一行单元格：先画"边框+底色"矩形，再在其上层写可自动换行的文本
    protected function drawRowCells($cells, $widths, $rowH, $lh, $pad, $fill, $fg, $fs)
    {
        $pdf = $this->pdf;
        // 整行在当前页放不下时主动换页。否则 Cell 内部自动分页会把边框画到
        // 新页顶部，而文本仍按旧坐标（可能已越过页底）书写，随后
        // SetXY(y+rowH) 落到页面高度之外，导致后续每行都触发分页，
        // 页数与内存超线性膨胀（数千页直至内存耗尽）。
        $trigger = $pdf->GetPageHeight() - 18;   // 与 SetAutoPageBreak(true, 18) 对应
        $area = $trigger - $pdf->getTM();
        if ($pdf->GetY() + $rowH > $trigger && $rowH <= $area) {
            $pdf->AddPage();
        }
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $pdf->SetFontSize($fs);
        foreach ($cells as $ci => $txt) {
            if (!isset($widths[$ci])) break;   // 异常行（单元格多于表头）时保护
            $pdf->SetXY($x, $y);
            // 边框 + 底色（先画，避免覆盖文字）
            $pdf->Cell($widths[$ci], $rowH, '', 1, 0, '', $fill);
            // 文本（可换行，带左右内边距）
            $pdf->SetXY($x + $pad, $y + 1.6);
            $pdf->SetTextColor($fg[0], $fg[1], $fg[2]);
            $pdf->MultiCell(max(6, $widths[$ci] - $pad * 2), $lh, $txt, 0, 'L');
            $x += $widths[$ci];
        }
        $pdf->SetXY($this->lm, $y + $rowH);
    }

    protected function drawTable($head, $rows)
    {
        $pdf = $this->pdf;
        $cols = count($head);
        if ($cols == 0) return;
        $avail = $this->rm - $this->lm;

        // 列宽分配：
        //   1) 每列先保证「表头不断字」所需的最小宽度——表头是固定短文本，
        //      被挤断（如 “字段(英文” 换行、“AUTO_INCREMEN/T”）最显眼；
        //   2) 剩余空间按各列「内容期望宽度 − 最小宽度」加权分配，内容期望
        //      取该列最宽单元格并封顶，避免长文本列吞噬全部可用宽度。
        $fs  = 9.8;
        $lh  = 5.7;      // 单元格内行距
        $pad = 2.4;      // 单元格左右内边距
        $cM  = 1.0;      // FPDF cMargin：MultiCell 会在此基础上再左右各减
        $innerPad = 2 * ($pad + $cM);   // 单元格内文本两侧合计占位(mm)
        // 单元格按纯文本渲染：先剥离 **加粗** / `代码` / [链接](url) 标记
        $head = array_map(array($this, 'plainText'), $head);
        // 列数严格以表头为准：数据行里多余的单元格丢弃、缺失的补空。
        // 否则某行多出一个未转义/多余的 | 就会把整表列数“撑大”，造成列错位。
        foreach ($rows as &$r) {
            $r = array_pad(array_slice($r, 0, $cols), $cols, '');
            $r = array_map(array($this, 'plainText'), $r);
        }
        unset($r);
        $pdf->SetFont('wqy', '', $fs);

        $MAXCOL = 62;    // 单列期望宽度上限(mm)，防止单个长文本列挤占全部空间
        $MINCOL = 16;    // 单列最小宽度(mm)，保证短词（如 KEY/NULL/PK）不被断字
        $minw = array(); $des = array();
        foreach ($head as $ci => $c) {
            // 下界同时满足：表头完整放下（+1.5mm 安全余量，避免浮点误差翻行）
            // + 不小于短词所需的最小宽度
            $minw[$ci] = max($MINCOL, $pdf->GetStringWidth($c) + $innerPad + 1.5);
            $des[$ci]  = $minw[$ci];
        }
        foreach ($rows as $r) {
            foreach ($r as $ci => $c) {
                if (!isset($minw[$ci])) { $minw[$ci] = 14; $des[$ci] = 14; }
                $w = $pdf->GetStringWidth($c) + $innerPad;
                if ($w > $des[$ci]) $des[$ci] = $w;
            }
        }
        foreach ($des as $ci => $w) $des[$ci] = min($w, max($minw[$ci], $MAXCOL));

        $sumMin = array_sum($minw);
        $widths = array();
        if ($sumMin >= $avail) {
            // 极端情况：仅表头就已超出可用宽度 → 等比压缩到可用宽度
            foreach ($minw as $ci => $w) $widths[$ci] = $w / $sumMin * $avail;
        } else {
            $widths = $minw;
            $left = $avail - $sumMin;
            $wts = array(); $sumW = 0.0;
            foreach ($des as $ci => $w) { $d = max(0.0, $w - $minw[$ci]); $wts[$ci] = $d; $sumW += $d; }
            foreach ($des as $ci => $w)
                $widths[$ci] += ($sumW > 0 ? $wts[$ci] / $sumW * $left : $left / $cols);
        }
        $widths[$cols - 1] += $avail - array_sum($widths);    // 修正浮点误差

        $pdf->Ln(2);
        $pdf->SetDrawColor(198, 209, 224);
        $pdf->SetLineWidth(0.3);

        // 行高上限：不超过一页可用高度（防止极端内容撑出多页高的行）
        $maxRowH = $pdf->GetPageHeight() - 18 - $pdf->getTM() - 2;

        // 表头（蓝底白字，同样支持换行）
        $rowH = 0;
        foreach ($head as $ci => $c)
            $rowH = max($rowH, $this->nbLines($widths[$ci] - $pad * 2, $c) * $lh + 3.6);
        $rowH = min($rowH, $maxRowH);
        $pdf->SetFillColor($this->accent[0], $this->accent[1], $this->accent[2]);
        $this->drawRowCells($head, $widths, $rowH, $lh, $pad, true, array(255, 255, 255), $fs);

        // 数据行（斑马底色，内容自动换行）
        $fill = false;
        $pdf->SetFillColor(244, 247, 251);
        foreach ($rows as $r) {
            $rowH = 0;
            foreach ($head as $ci => $_) {
                $txt = isset($r[$ci]) ? $r[$ci] : '';
                $rowH = max($rowH, $this->nbLines($widths[$ci] - $pad * 2, $txt) * $lh + 3.2);
            }
            $rowH = min($rowH, $maxRowH);
            $this->drawRowCells($r, $widths, $rowH, $lh, $pad, $fill, array(45, 55, 72), $fs);
            $fill = !$fill;
        }
        $pdf->SetTextColor(30, 34, 40);
        $pdf->Ln(3);
    }

    /* ---------------- 行内富文本 ---------------- */

    // 拆分为 token：array(type, text)，type ∈ plain|bold|code|link
    protected function tokenize($text)
    {
        $tokens = array();
        $parts = preg_split('/(\*\*[^*]+\*\*|__[^_]+__|`[^`]+`|\[[^\]]+\]\([^)\s]+\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $p) {
            if ($p === '' || $p === null) continue;
            if (preg_match('/^\*\*(.+)\*\*$|^__(.+)__$/', $p, $m)) {
                $tokens[] = array('bold', isset($m[1]) ? $m[1] : $m[2]);
            } elseif (preg_match('/^`(.+)`$/', $p, $m)) {
                $tokens[] = array('code', $m[1]);
            } elseif (preg_match('/^\[([^\]]+)\]\(([^)\s]+)\)$/', $p, $m)) {
                $tokens[] = array('link', $m[1], $m[2]);
            } else {
                $tokens[] = array('plain', $p);
            }
        }
        return $tokens;
    }

    // 段落级流式输出（结束后整行换行，复位 x 到左边距）
    protected function richFlow($text, $size, $lh)
    {
        $this->richFlowRaw($text, $size, $lh);
        $this->pdf->Ln($lh);   // Write 结束时光标停在本行内，必须推进整行高
    }

    protected function richFlowRaw($text, $size, $lh)
    {
        $pdf = $this->pdf;
        foreach ($this->tokenize($text) as $tk) {
            switch ($tk[0]) {
                case 'bold':
                    $pdf->SetTextColor(12, 24, 44);
                    $pdf->SetFont('wqy', '', $size);
                    $pdf->Write($lh, $tk[1]);
                    break;
                case 'code':
                    $pdf->SetTextColor($this->codeFg[0], $this->codeFg[1], $this->codeFg[2]);
                    $pdf->SetFont('wqy', '', $size - 0.6);
                    $pdf->Write($lh, $tk[1]);
                    break;
                case 'link':
                    $pdf->SetTextColor($this->link[0], $this->link[1], $this->link[2]);
                    $pdf->SetFont('wqy', 'U', $size);
                    $pdf->Write($lh, $tk[1], isset($tk[2]) ? $tk[2] : '');
                    break;
                default:
                    $pdf->SetTextColor(30, 34, 40);
                    $pdf->SetFont('wqy', '', $size);
                    $pdf->Write($lh, $tk[0] === 'plain' ? $tk[1] : $tk[0]);
            }
        }
        $pdf->SetTextColor(30, 34, 40);
    }
}

/* ===========================================================================
 * 请求分发
 * =========================================================================== */

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

if ($action === 'convert') {
    // 致命错误兜底：保证任何情况下前端拿到的是 JSON 而非空白
    register_shutdown_function('md2pdf_fatal_guard');

    /* ---- 依赖与字体可用性（缺失时给出可操作的提示） ---- */
    $fail = '';
    if (!empty($DEPS_MISSING)) {
        $fail = '缺少依赖文件：' . implode('、', $DEPS_MISSING)
              . '。请将它们与 index.php 放在同一目录后重试。';
    } elseif (!class_exists('PDF_Chinese', false)) {
        $fail = 'FPDF 中文扩展加载失败，请检查 fpdf.php / chinese.php 是否完整。';
    } elseif ($FONT === '') {
        $fail = '未找到中文字体文件（assets/wqy-microhei.ttf），请将该字体放到 index.php 同目录或 assets/ 子目录。';
    }
    if ($fail !== '') {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        exit(json_encode(array('ok' => false, 'error' => $fail)));
    }

    /* ---- 请求体超过 post_max_size 时 PHP 会清空 $_POST/$_FILES ---- */
    $postMax = trim(ini_get('post_max_size'));
    if ($postMax !== '') {
        $unit = strtoupper(substr($postMax, -1));
        $val  = (int)$postMax;
        if ($unit === 'K') $val *= 1024;
        elseif ($unit === 'M') $val *= 1024 * 1024;
        elseif ($unit === 'G') $val *= 1024 * 1024 * 1024;
        $clen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
        if ($val > 0 && $clen > $val) {
            while (ob_get_level()) { @ob_end_clean(); }
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            exit(json_encode(array('ok' => false,
                'error' => '请求大小（' . round($clen / 1048576, 1) . 'MB）超过服务器 post_max_size 限制（'
                         . round($val / 1048576, 1) . 'MB），请联系管理员调整 php.ini，或粘贴文本代替上传文件。')));
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    /* ---- 取得 Markdown 源 ---- */
    $md = '';
    $srcName = '';
    $uploadErrMsg = array(
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器 upload_max_filesize 限制',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单大小限制',
        UPLOAD_ERR_PARTIAL    => '文件上传不完整，请重试',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录，请联系管理员',
        UPLOAD_ERR_CANT_WRITE => '服务器磁盘写入失败，请联系管理员',
        UPLOAD_ERR_EXTENSION  => '上传被 PHP 扩展中断',
    );
    if (isset($_FILES['mdfile']) && is_array($_FILES['mdfile'])
        && isset($_FILES['mdfile']['error']) && $_FILES['mdfile']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uerr = (int)$_FILES['mdfile']['error'];
        if ($uerr !== UPLOAD_ERR_OK) {
            while (ob_get_level()) { @ob_end_clean(); }
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            exit(json_encode(array('ok' => false,
                'error' => isset($uploadErrMsg[$uerr]) ? $uploadErrMsg[$uerr] : ('上传失败（错误码 ' . $uerr . '）'))));
        }
        $tmp  = $_FILES['mdfile']['tmp_name'];
        $size = (int)$_FILES['mdfile']['size'];
        $orig = isset($_FILES['mdfile']['name']) ? $_FILES['mdfile']['name'] : 'document.md';
        if ($size <= 0 || $size > MAX_TEXT_BYTES) {
            http_response_code(400);
            exit(json_encode(array('ok' => false, 'error' => '文件超出大小限制（最大 2MB）')));
        }
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, array('md', 'markdown', 'txt'), true)) {
            http_response_code(400);
            exit(json_encode(array('ok' => false, 'error' => '仅支持 .md / .markdown / .txt 文件')));
        }
        if (strpos($tmp, "\0") !== false || !is_uploaded_file($tmp)) {
            http_response_code(400);
            exit(json_encode(array('ok' => false, 'error' => '上传文件无效')));
        }
        $md = file_get_contents($tmp);
        $srcName = pathinfo($orig, PATHINFO_FILENAME);
    } elseif (isset($_POST['mdtext']) && is_string($_POST['mdtext']) && $_POST['mdtext'] !== '') {
        if (strlen($_POST['mdtext']) > MAX_TEXT_BYTES) {
            http_response_code(400);
            exit(json_encode(array('ok' => false, 'error' => '粘贴内容超出大小限制（最大 2MB）')));
        }
        $md = $_POST['mdtext'];
        $srcName = isset($_POST['docname']) && $_POST['docname'] !== '' ? $_POST['docname'] : '';
    }

    $md = str_replace("\r\n", "\n", $md);
    $md = str_replace("\r", "\n", $md);
    $md = preg_replace('/^\xEF\xBB\xBF/', '', $md);          // 去 BOM
    $md = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $md); // 去控制字符
    if (trim($md) === '') {
        http_response_code(400);
        exit(json_encode(array('ok' => false, 'error' => '内容为空：请上传 .md 文件或粘贴 Markdown 文本')));
    }

    /* ---- 生成 PDF ---- */
    try {
        // 预先提取首个一级标题作为文档标题：必须在渲染前设置，
        // 因为前 n-1 页的页脚在渲染过程中就已写入
        $title = $srcName !== '' ? $srcName : 'Markdown 文档';
        if (preg_match('/^#\s+(.+)\s*$/m', $md, $tm)) {
            $title = trim($tm[1]);
        }

        $pdf = new MD_PDF('P', 'mm', 'A4');
        $pdf->AddFontTTF('wqy', '', $FONT);
        $pdf->docTitle = $title;
        $pdf->SetTitle($title, true);
        $pdf->SetCreator('MD2PDF (FPDF Chinese Toolkit)', true);

        $renderer = new MD_Renderer($pdf);
        $renderer->render($md);

        // 关键：显式 Close() 让最后一页页脚以占位符形式写入页面缓冲，
        // 之后 Output 前的 _finalizeTTFonts() 才能把页脚文字一并回填为
        // 内嵌字形（否则页脚在子集化之后才渲染，会变成乱码）。
        $pdf->Close();
        $data = $pdf->Output('S');
    } catch (Exception $e) {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        exit(json_encode(array('ok' => false, 'error' => 'PDF 生成失败：' . $e->getMessage())));
    } catch (Throwable $e) {   // PHP 7 Error（TypeError 等）也兜住
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        exit(json_encode(array('ok' => false, 'error' => 'PDF 生成失败：' . $e->getMessage())));
    }

    /* ---- 输出文件名（清洗 + RFC5987） ---- */
    $safe = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/', '', $title);
    $safe = trim($safe);
    if ($safe === '') $safe = 'document';
    if (function_exists('mb_substr')) $safe = mb_substr($safe, 0, 60, 'UTF-8');
    else $safe = substr($safe, 0, 120);
    $fname = $safe . '.pdf';

    // 丢弃此前缓冲中的任何意外输出，保证响应体是纯净的 PDF 二进制
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($data));
    header('X-Doc-Name: ' . rawurlencode($fname));
    header('X-Doc-Size: ' . strlen($data));
    header('Content-Disposition: attachment; filename="document.pdf"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Cache-Control: no-store');
    echo $data;
    exit;
}

/* ===========================================================================
 * 以下为前端页面（HTML5 + CSS3 + AJAX）
 * =========================================================================== */
while (ob_get_level()) { @ob_end_clean(); }
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Markdown → PDF 转换器</title>
<style>
  :root{
    --bg1:#0f1b33; --bg2:#1d3a6e;
    --accent:#2f7cf6; --accent2:#22c3e6;
    --ink:#1c2430; --muted:#5c6b80; --line:#dfe6f0;
    --card:#ffffff; --ok:#12a150; --err:#d64545;
    --radius:14px;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{
    min-height:100vh;
    font-family:"PingFang SC","Microsoft YaHei","Hiragino Sans GB","Noto Sans CJK SC",sans-serif;
    color:var(--ink);
    background:linear-gradient(135deg,var(--bg1) 0%,var(--bg2) 55%,#2a56a8 100%);
    display:flex; flex-direction:column; align-items:center;
    padding:38px 16px 60px;
  }
  .hero{text-align:center;color:#fff;margin-bottom:26px;}
  .hero h1{
    margin:0 0 8px;font-size:30px;letter-spacing:1px;font-weight:700;
    background:linear-gradient(90deg,#fff,#bfe0ff);
    -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
  }
  .hero p{margin:0;color:#b9cbe8;font-size:14px;}
  .hero .chips{margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
  .chip{
    background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);
    color:#dce9fb;font-size:12px;padding:4px 12px;border-radius:999px;backdrop-filter:blur(4px);
  }
  .card{
    width:100%;max-width:880px;background:var(--card);
    border-radius:var(--radius);
    box-shadow:0 18px 50px rgba(4,12,30,.45);
    overflow:hidden;
  }
  .tabs{display:flex;border-bottom:1px solid var(--line);}
  .tab{
    flex:1;text-align:center;padding:15px 0;font-size:15px;cursor:pointer;
    color:var(--muted);background:#f7f9fc;border-bottom:3px solid transparent;
    user-select:none;transition:.18s;
  }
  .tab:hover{color:var(--accent);}
  .tab.active{color:var(--accent);background:#fff;border-bottom-color:var(--accent);font-weight:600;}
  .panel{display:none;padding:26px 28px 8px;}
  .panel.active{display:block;animation:fade .25s ease;}
  @keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

  /* 上传区 */
  .drop{
    border:2px dashed #c4d4ec;border-radius:12px;background:#f8fbff;
    padding:44px 20px;text-align:center;cursor:pointer;transition:.2s;
  }
  .drop:hover,.drop.over{border-color:var(--accent);background:#eef5ff;transform:translateY(-1px);}
  .drop .icon{font-size:44px;line-height:1;}
  .drop .t1{margin-top:10px;font-size:15.5px;font-weight:600;color:var(--ink);}
  .drop .t2{margin-top:4px;font-size:12.5px;color:var(--muted);}
  .fileinfo{
    margin-top:14px;display:none;align-items:center;gap:10px;
    background:#eef7ff;border:1px solid #cfe4fb;border-radius:10px;padding:10px 14px;font-size:13.5px;
  }
  .fileinfo.show{display:flex;}
  .fileinfo .name{flex:1;color:#134a8e;font-weight:600;word-break:break-all;}
  .fileinfo .clear{cursor:pointer;color:#98a7bd;font-size:18px;line-height:1;user-select:none;}
  .fileinfo .clear:hover{color:var(--err);}

  /* 粘贴区 */
  textarea{
    width:100%;height:300px;resize:vertical;
    border:1.5px solid var(--line);border-radius:10px;
    padding:14px 16px;font-size:13.5px;line-height:1.7;color:var(--ink);
    font-family:"SFMono-Regular",Consolas,"Courier New",monospace;
    background:#fbfcfe;outline:none;transition:.15s;
  }
  textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(47,124,246,.14);background:#fff;}
  .paste-tools{display:flex;justify-content:space-between;align-items:center;margin-top:10px;}
  .paste-tools .hint{font-size:12px;color:var(--muted);}
  .linkbtn{
    background:none;border:none;color:var(--accent);font-size:12.5px;cursor:pointer;padding:0;
  }
  .linkbtn:hover{text-decoration:underline;}

  .actions{padding:8px 28px 26px;display:flex;gap:12px;align-items:center;}
  .btn{
    flex:1;padding:14px 18px;border:none;border-radius:11px;cursor:pointer;
    font-size:15.5px;font-weight:600;color:#fff;
    background:linear-gradient(90deg,var(--accent),var(--accent2));
    box-shadow:0 8px 18px rgba(47,124,246,.32);
    transition:.2s;display:flex;align-items:center;justify-content:center;gap:8px;
  }
  .btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 24px rgba(47,124,246,.4);}
  .btn:disabled{opacity:.55;cursor:not-allowed;box-shadow:none;}
  .btn .spin{
    width:17px;height:17px;border:2.5px solid rgba(255,255,255,.4);
    border-top-color:#fff;border-radius:50%;display:none;
    animation:rot .7s linear infinite;
  }
  .btn.loading .spin{display:inline-block;}
  @keyframes rot{to{transform:rotate(360deg)}}

  .result{
    display:none;margin:0 28px 26px;padding:18px 20px;border-radius:12px;
    background:#f0fbf4;border:1px solid #bfe8cf;animation:fade .3s ease;
  }
  .result.show{display:block;}
  .result .row1{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .result .ok{color:var(--ok);font-weight:700;font-size:15px;}
  .result .meta{color:var(--muted);font-size:12.5px;}
  .result .ops{margin-left:auto;display:flex;gap:10px;}
  .rbtn{
    padding:8px 18px;border-radius:8px;border:none;cursor:pointer;font-size:13.5px;font-weight:600;
    background:linear-gradient(90deg,var(--ok),#3ecf7a);color:#fff;transition:.18s;
  }
  .rbtn:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(18,161,80,.3);}
  .rbtn.ghost{background:#fff;color:var(--accent);border:1.5px solid var(--accent);}
  .rbtn.ghost:hover{background:#eff6ff;box-shadow:none;}

  .msg{
    display:none;margin:0 28px 22px;padding:12px 16px;border-radius:10px;font-size:13.5px;
    background:#fdf0f0;border:1px solid #f2c8c8;color:var(--err);
  }
  .msg.show{display:block;}

  footer.site{
    margin-top:26px;color:#8ea6cc;font-size:12px;text-align:center;line-height:1.8;
  }
  footer.site code{
    background:rgba(255,255,255,.1);padding:1px 7px;border-radius:5px;color:#cfe0f7;font-family:Consolas,monospace;
  }
  @media (max-width:640px){
    .panel{padding:18px 16px 4px;}
    .actions{padding:6px 16px 20px;}
    .result{margin:0 16px 20px;}
    .hero h1{font-size:24px;}
  }
</style>
</head>
<body>

  <div class="hero">
    <h1>Markdown → PDF 转换器</h1>
    <p>上传 .md 文件或直接粘贴 Markdown，一键生成精美 A4 排版 PDF 并下载</p>
    <div class="chips">
      <span class="chip">中文字体内嵌 · 任何设备显示一致</span>
      <span class="chip">标题 / 列表 / 表格 / 代码块</span>
      <span class="chip">引用 / 链接 / 分隔线</span>
      <span class="chip">页脚自动页码</span>
    </div>
  </div>

  <div class="card">
    <div class="tabs">
      <div class="tab active" id="tabFile" data-panel="panelFile">上传 .md 文件</div>
      <div class="tab" id="tabPaste" data-panel="panelPaste">粘贴 Markdown</div>
    </div>

    <!-- 上传面板 -->
    <div class="panel active" id="panelFile">
      <div class="drop" id="drop">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" style="display:block;margin:0 auto;">
          <path d="M6 2h8l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"
                stroke="#2f7cf6" stroke-width="1.6" stroke-linejoin="round" fill="#eaf3ff"/>
          <path d="M14 2v4h4" stroke="#2f7cf6" stroke-width="1.6" stroke-linejoin="round" fill="none"/>
          <path d="M8.5 12h7M8.5 15.5h7M8.5 8.5H11" stroke="#2f7cf6" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
        <div class="t1">点击选择 或 拖拽 .md 文件到此处</div>
        <div class="t2">支持 .md / .markdown / .txt，最大 2MB</div>
      </div>
      <input type="file" id="file" accept=".md,.markdown,.txt" hidden>
      <div class="fileinfo" id="fileinfo">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M21 12.5l-8.5 8.5a6 6 0 0 1-8.5-8.5L12.5 4a4 4 0 0 1 5.66 5.66L9.8 18a2 2 0 0 1-2.83-2.83l7.8-7.8" stroke="#2f7cf6" stroke-width="1.7" stroke-linecap="round"/></svg>
        <span class="name" id="filename"></span>
        <span class="meta" id="filesize"></span>
        <span class="clear" id="fileclear" title="移除">✕</span>
      </div>
    </div>

    <!-- 粘贴面板 -->
    <div class="panel" id="panelPaste">
      <textarea id="mdtext" placeholder="# 在此粘贴或输入 Markdown&#10;&#10;支持：**加粗**、`行内代码`、[链接](https://example.com)、&#10;- 列表、1. 有序列表、> 引用、表格、``` 代码块、--- 分隔线"></textarea>
      <div class="paste-tools">
        <span class="hint" id="charcount">0 字符</span>
        <button class="linkbtn" id="sample" type="button">填入示例文档</button>
      </div>
    </div>

    <div class="actions">
      <button class="btn" id="go" type="button">
        <span class="spin"></span><span id="goText">生成 PDF 并下载</span>
      </button>
    </div>

    <div class="msg" id="msg"></div>

    <div class="result" id="result">
      <div class="row1">
        <span class="ok">✓ 转换成功</span>
        <span class="meta" id="rmeta"></span>
        <div class="ops">
          <button class="rbtn" id="download" type="button">下载 PDF</button>
          <button class="rbtn ghost" id="preview" type="button">浏览器预览</button>
        </div>
      </div>
    </div>
  </div>

  <footer class="site">
    基于 <code>fpdf.php + chinese.php + ttf.php</code>（FPDF 中文扩展）· 文泉驿微米黑已子集化内嵌 · PHP 单文件实现
  </footer>

<script>
(function(){
  'use strict';
  var $ = function(id){ return document.getElementById(id); };
  var tabFile = $('tabFile'), tabPaste = $('tabPaste');
  var panelFile = $('panelFile'), panelPaste = $('panelPaste');
  var drop = $('drop'), fileInput = $('file');
  var fileInfo = $('fileinfo'), fileName = $('filename'), fileSize = $('filesize');
  var mdText = $('mdtext'), charcount = $('charcount');
  var go = $('go'), goText = $('goText'), msg = $('msg'), result = $('result');
  var blobUrl = null, blobName = 'document.pdf';

  /* ---------- Tab 切换 ---------- */
  function switchTab(tab, panel){
    [tabFile, tabPaste].forEach(function(t){ t.classList.remove('active'); });
    [panelFile, panelPaste].forEach(function(p){ p.classList.remove('active'); });
    tab.classList.add('active'); panel.classList.add('active');
  }
  tabFile.addEventListener('click', function(){ switchTab(tabFile, panelFile); });
  tabPaste.addEventListener('click', function(){ switchTab(tabPaste, panelPaste); });

  /* ---------- 文件选择 / 拖拽 ---------- */
  drop.addEventListener('click', function(){ fileInput.click(); });
  ['dragenter','dragover'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave','drop'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function(e){
    if (e.dataTransfer.files && e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
  });
  fileInput.addEventListener('change', function(){
    if (fileInput.files && fileInput.files.length) setFile(fileInput.files[0]);
  });
  function fmtSize(n){
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n/1024).toFixed(1) + ' KB';
    return (n/1048576).toFixed(2) + ' MB';
  }
  var curFile = null;
  function setFile(f){
    var ok = /\.(md|markdown|txt)$/i.test(f.name);
    if (!ok){ showMsg('仅支持 .md / .markdown / .txt 文件'); return; }
    if (f.size > 2*1024*1024){ showMsg('文件超过 2MB 限制'); return; }
    hideMsg(); curFile = f;
    fileName.textContent = f.name;
    fileSize.textContent = fmtSize(f.size);
    fileInfo.classList.add('show');
  }
  $('fileclear').addEventListener('click', function(e){
    e.stopPropagation();
    curFile = null; fileInput.value = '';
    fileInfo.classList.remove('show');
  });

  /* ---------- 字数统计 / 示例 ---------- */
  mdText.addEventListener('input', function(){
    charcount.textContent = mdText.value.length + ' 字符';
  });
  $('sample').addEventListener('click', function(){
    mdText.value = [
      '# 季度工作总结','',
      '本季度围绕 **性能优化** 与 `自动化运维` 两条主线推进，整体达成率 96%。','',
      '## 一、主要成果','',
      '- 核心接口平均响应时间由 380ms 降至 **120ms**',
      '- 完成 CI 流水线搭建，构建时间缩短 60%',
      '- 上线 SSL 证书到期监控，覆盖 52 个域名','',
      '## 二、关键指标','',
      '| 指标 | 目标 | 实际 | 达成 |',
      '| --- | --- | --- | --- |',
      '| 可用性 | 99.9% | 99.98% | ✅ |',
      '| 响应时间 P99 | ≤200ms | 168ms | ✅ |',
      '| 故障数 | ≤2 | 1 | ✅ |','',
      '## 三、代码示例','',
      '```',
      'def health_check(domain):',
      '    cert = fetch_cert(domain)',
      '    return (cert.not_after - today()).days',
      '```','',
      '## 四、下季度计划','',
      '1. 引入灰度发布机制',
      '2. 建设统一告警平台，参考 [内部 Wiki](https://example.com)',
      '3. 完成多机房容灾演练','',
      '> 备注：以上数据统计周期为本季度 1-3 月。','',
      '---',
      '如有疑问请联系运维组。'
    ].join('\n');
    mdText.dispatchEvent(new Event('input'));
    switchTab(tabPaste, panelPaste);
  });

  /* ---------- 提交转换 ---------- */
  go.addEventListener('click', convert);
  mdText.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') convert();
  });

  function showMsg(t){ msg.textContent = t; msg.classList.add('show'); result.classList.remove('show'); }
  function hideMsg(){ msg.classList.remove('show'); }

  function convert(){
    hideMsg(); result.classList.remove('show');
    var useFile = tabFile.classList.contains('active');
    var hasFile = curFile !== null;
    var text = mdText.value;
    if (useFile && !hasFile){ showMsg('请先选择一个 .md 文件（或切换到"粘贴 Markdown"）'); return; }
    if (!useFile && !text.trim()){ showMsg('粘贴内容为空'); return; }

    var fd = new FormData();
    fd.append('action', 'convert');
    if (useFile){ fd.append('mdfile', curFile); }
    else {
      fd.append('mdtext', text);
      fd.append('docname', '');
    }
    go.disabled = true; go.classList.add('loading'); goText.textContent = '正在生成 PDF …';

    fetch(location.pathname + '?action=convert', { method:'POST', body: fd })
      .then(function(res){
        var ct = res.headers.get('Content-Type') || '';
        if (!res.ok || ct.indexOf('application/pdf') === -1){
          // 用 text() 解析而非 json()：后端异常时响应可能为空或非 JSON，
          // 直接调 json() 会报 "Unexpected end of JSON input" 这类无意义错误
          return res.text().then(function(t){
            var m = '';
            try {
              var j = JSON.parse(t);
              if (j && j.error) m = j.error;
            } catch(e) { m = ''; }
            if (!m) {
              m = '服务器返回异常响应（HTTP ' + res.status + (t ? '，' + t.slice(0, 120) : '，空响应') + '）。'
                + '请确认 index.php 与 fpdf.php / chinese.php / ttf.php、assets/wqy-microhei.ttf '
                + '位于同一目录，并查看服务器 PHP 错误日志。';
            }
            throw new Error(m);
          });
        }
        var rawName = res.headers.get('X-Doc-Name');
        blobName = rawName ? decodeURIComponent(rawName) : 'document.pdf';
        return res.blob();
      })
      .then(function(blob){
        if (blobUrl) URL.revokeObjectURL(blobUrl);
        blobUrl = URL.createObjectURL(blob);
        $('rmeta').textContent = blobName + ' · ' + fmtSize(blob.size);
        result.classList.add('show');
        // 自动触发下载
        var a = document.createElement('a');
        a.href = blobUrl; a.download = blobName;
        document.body.appendChild(a); a.click(); a.remove();
      })
      .catch(function(err){
        showMsg('转换失败：' + err.message);
      })
      .then(function(){
        go.disabled = false; go.classList.remove('loading'); goText.textContent = '生成 PDF 并下载';
      });
  }

  $('download').addEventListener('click', function(){
    if (!blobUrl) return;
    var a = document.createElement('a');
    a.href = blobUrl; a.download = blobName;
    document.body.appendChild(a); a.click(); a.remove();
  });
  $('preview').addEventListener('click', function(){
    if (blobUrl) window.open(blobUrl, '_blank');
  });
})();
</script>
</body>
</html>
