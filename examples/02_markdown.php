<?php
/**
 * 示例 2：把 .md 文档转换为多页 PDF，并在每页底部居中显示页码（第 n / m 页）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }

// 带页码的 PDF 子类：重写 Footer 在底部居中输出“第 n / m 页”
class PDF_MD extends PDF_Chinese
{
    function Footer()
    {
        $this->SetY(-14);
        $this->SetFont('GB', '', 9);
        $this->SetTextColor(120, 120, 120);
        // {nb} 会在输出时被替换为总页数
        $this->Cell(0, 8, zh('第 ' . $this->PageNo() . ' / {nb} 页'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}

// 内联富文本渲染（支持 **加粗** 切换字体样式）；段落结束后用公开的 Ln() 复位到左边距
function md_rich($pdf, $text, $size = 11, $style = '')
{
    $lh = $size * 0.6;
    $parts = preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        if ($p === '') continue;
        $isBold = ($i % 2 == 1);
        $pdf->SetFont('GB', $style . ($isBold ? 'B' : ''), $size);
        $pdf->Write($lh, zh($p));
    }
    $pdf->Ln($lh); // 公开方法：x 复位到左边距，y 下移一行
}

$pdf = new PDF_MD('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 18);
$pdf->AliasNbPages('{nb}'); // 注册总页数别名
$pdf->AddPage();

$pw = $pdf->GetPageWidth();   // 公开方法，获取页面宽度
$lm = 10;                    // 默认左边距（mm）
$rm = $pw - 10;              // 右边距位置

$md = file_get_contents(__DIR__ . '/assets/sample.md');
$lines = explode("\n", $md);

$inCode = false;
foreach ($lines as $ln) {
    $ln = rtrim($ln, "\r");
    if ($inCode) {
        if (trim($ln) === '```') { $inCode = false; $pdf->SetFont('GB', '', 11); continue; }
        $pdf->SetFillColor(238, 238, 238);
        $pdf->SetFont('GB', '', 10);
        $pdf->MultiCell(0, 6, zh($ln), 1, 'L', true);
        continue;
    }
    if (trim($ln) === '```') { $inCode = true; $pdf->Ln(1); continue; }
    if ($ln === '') { $pdf->Ln(2); continue; }

    // 分隔线
    if (preg_match('/^(\s*[-*_]){3,}\s*$/', $ln)) {
        $y = $pdf->GetY();
        $pdf->Line($lm, $y, $rm, $y);
        $pdf->Ln(5);
        continue;
    }
    // 标题
    if (preg_match('/^(#{1,3})\s+(.*)$/', $ln, $m)) {
        $lvl = strlen($m[1]);
        $sz = $lvl == 1 ? 18 : ($lvl == 2 ? 14 : 12);
        $pdf->Ln(2);
        md_rich($pdf, $m[2], $sz, 'B');
        continue;
    }
    // 有序列表
    if (preg_match('/^(\d+)\.\s+(.*)$/', $ln, $m)) {
        $x = $pdf->GetX();
        $pdf->Cell(9, 6.6, zh($m[1] . '.'), 0, 0, 'R');
        $pdf->SetX($x + 9);
        md_rich($pdf, $m[2], 11);
        continue;
    }
    // 无序列表
    if (preg_match('/^[\-\*]\s+(.*)$/', $ln, $m)) {
        $x = $pdf->GetX();
        $pdf->Cell(6, 6.6, zh('•'), 0, 0, 'C');
        $pdf->SetX($x + 6);
        md_rich($pdf, $m[1], 11);
        continue;
    }
    // 引用行（去除前导 >）
    if (preg_match('/^>\s?(.*)$/', $ln, $m)) {
        $pdf->SetTextColor(90, 90, 90);
        md_rich($pdf, $m[1], 11);
        $pdf->SetTextColor(0, 0, 0);
        continue;
    }
    // 普通段落
    md_rich($pdf, $ln, 11);
}

$pdf->Output('F', __DIR__ . '/output/02_markdown.pdf');
echo "02_markdown.pdf 生成完成\n";
