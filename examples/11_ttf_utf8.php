<?php
/**
 * 示例 11：UTF-8 原生 + TrueType 内嵌字体（推荐写法）
 *
 * 与示例 1~10 的最大区别：
 *   - 不再需要 iconv() 手动转码，源码里的中文直接以 UTF-8 传入即可；
 *   - 中文、英文、标点、emoji 在同一字体里统一处理，阅读端无需安装任何
 *     Adobe 亚洲字体（字体已子集化内嵌进 PDF）；
 *   - 断行按“码点（grapheme）”计算，中英混排的宽度与换行更准确。
 *
 * 关键 API：
 *   AddFontTTF($family, $style, $ttfPath)  从字体文件注册
 *   AddFontFromStream($family, $style, $bytes)  从内存字节注册
 *   SetFont($family, $style, $size)        与内置字体用法一致
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddFontTTF('wqy', '', $A . 'wqy-microhei.ttf');   // 注册内嵌 TrueType 字体
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ---- 标题 ----
$pdf->SetFont('wqy', '', 26);
$pdf->Cell(0, 16, 'TrueType 内嵌字体 · UTF-8 原生排版', 0, 1, 'C');
$pdf->SetFont('wqy', '', 11);
$pdf->SetTextColor(110, 110, 110);
$pdf->Cell(0, 8, '无需 iconv 转码，无需阅读器安装中文字体', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(4);

// ---- 正文：中英混排自动换行 ----
$pdf->SetFont('wqy', '', 12);
$body = "本示例演示 UTF-8 原生输入：源码中的中文、English words、数字 12345 以及符号（、。）"
      . "都可以原样传给 Cell / MultiCell / Write，由库内部按码点处理宽度与断行。"
      . "相比早期的 GB/Big5（EUC）CID 方案，不再依赖阅读端 Adobe 亚洲字体包，"
      . "跨平台（Windows / macOS / Linux / 手机）显示效果一致，避免出现方块字。";
$pdf->MultiCell(0, 8, $body);
$pdf->Ln(4);

// ---- Write 流式输出 ----
$pdf->SetFont('wqy', '', 12);
$pdf->Write(8, "Write() 流式写法同样支持中文混排：这是第一句话，接着继续写 English sentence，"
             . "然后换行会自动处理。\n");
$pdf->Write(8, "第二行：换行符 \\n 也会被正确识别。\n");
$pdf->Ln(6);

// ---- 字形缺字行为 ----
$pdf->SetFont('wqy', '', 11);
$pdf->SetFillColor(245, 247, 250);
$pdf->MultiCell(0, 7,
    "说明：若字体本身缺少某个字形（例如本字体不含 emoji 😀），该字符会被留空而不是崩溃；"
  . "码点解析、宽度计算与断行仍然正常。要显示更多字符，请换用覆盖范围更大的字体。",
  1, 'L', true);
$pdf->Ln(8);

// ---- 一个简单表格，验证对齐 ----
$pdf->SetFont('wqy', '', 11);
$hdr = array('项目', '数量', '单价', '金额');
$w   = array(70, 30, 40, 40);
$pdf->SetFillColor(28, 99, 169);
$pdf->SetTextColor(255, 255, 255);
foreach ($hdr as $i => $t) {
    $pdf->Cell($w[$i], 9, $t, 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);
$rows = array(
    array('设计服务', '1', '8000.00', '8000.00'),
    array('前端开发（含中英混排 English）', '1', '12000.00', '12000.00'),
    array('部署与培训', '2', '1500.00', '3000.00'),
);
$fill = false;
foreach ($rows as $r) {
    foreach ($r as $i => $t) {
        $al = ($i == 0) ? 'L' : 'R';
        $pdf->Cell($w[$i], 9, $t, 1, 0, $al, $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
}

$out = __DIR__ . '/output/11_ttf_utf8.pdf';
$pdf->Output('F', $out);
echo "11_ttf_utf8.pdf 生成完成（" . filesize($out) . " 字节）\n";
