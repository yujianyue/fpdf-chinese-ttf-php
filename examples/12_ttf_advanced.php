<?php
/**
 * 示例 12：内嵌字体进阶 —— AddFontFromStream、多字号、中英双语排版
 *
 * 演示要点：
 *   1) AddFontFromStream()：字体可从内存字节注册（适合从数据库/远端加载字体）；
 *   2) 同一文档内多字号、多颜色、居中/左右对齐混合排版；
 *   3) 中英混排 + 长段落自动换行 + 页脚页码，整套不依赖阅读器字体；
 *   4) 生成结果可用 pdffonts 验证：emb=yes / sub=yes / uni=yes（已子集化内嵌）。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

$A = __DIR__ . '/assets/';

// 从内存字节注册字体（等价于 AddFontTTF(..., $file)）
$fontBytes = file_get_contents($A . 'wqy-microhei.ttf');
if ($fontBytes === false) {
    exit("找不到字体文件：{$A}wqy-microhei.ttf\n");
}

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddFontFromStream('ink', '', $fontBytes);          // 从流注册
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// ---- 顶部双语标题 ----
$pdf->SetFont('ink', '', 22);
$pdf->Cell(0, 14, '数 据 服 务 协 议', 0, 1, 'C');
$pdf->SetFont('ink', '', 10);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(0, 6, 'Data Service Agreement', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Line(20, 32, 190, 32);
$pdf->Ln(8);

// ---- 条款段落（中英混排自动换行） ----
$pdf->SetFont('ink', '', 11);
$pdf->Cell(0, 7, '第一条  服务内容 / Scope of Service', 0, 1);
$pdf->SetFont('ink', '', 10.5);
$pdf->MultiCell(0, 7,
    "甲方委托乙方提供数据处理与可视化服务（Data Processing & Visualization）。"
  . "乙方应按照本合同约定的时间表（schedule）交付，确保数据准确率不低于 99.9%。"
  . "涉及英文术语如 SLA、QPS、Latency P99 时，均以本协议定义为准。");
$pdf->Ln(3);

// ---- 关键指标表格 ----
$pdf->SetFont('ink', '', 11);
$pdf->Cell(0, 7, '第二条  关键指标 / Key Metrics', 0, 1);
$pdf->SetFont('ink', '', 10);
$w = array(60, 50, 70);
$pdf->SetFillColor(238, 242, 247);
$rows = array(
    array('指标 Metric', '目标值 Target', '说明 Note'),
    array('可用性 Availability', '≥ 99.9%', '按月统计 / monthly'),
    array('响应时间 Latency', '≤ 200 ms', 'P99 分位'),
    array('吞吐 QPS', '≥ 5000', '峰值 / peak'),
);
$fill = false;
foreach ($rows as $r) {
    foreach ($r as $i => $t) {
        $pdf->Cell($w[$i], 9, $t, 1, 0, 'L', $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
}
$pdf->Ln(6);

// ---- 缺字与 emoji 说明框 ----
$pdf->SetFont('ink', '', 10);
$pdf->SetFillColor(255, 249, 230);
$pdf->MultiCell(0, 7,
    "提示：本字体不含 Emoji/生僻字时，对应字符留空（不会崩溃），其余文字正常显示。"
  . "例如这里的 emoji 会被跳过：😀 🚀 ✅。要完整显示可换用覆盖更全的字体。",
  1, 'L', true);
$pdf->Ln(8);

// ---- 签名区 ----
$pdf->SetFont('ink', '', 10.5);
$pdf->Cell(90, 8, '甲方（盖章）：________________', 0, 0);
$pdf->Cell(90, 8, '乙方（盖章）：________________', 0, 1);
$pdf->Ln(2);
$pdf->Cell(90, 8, '日期 Date：2026-10-15', 0, 0);
$pdf->Cell(90, 8, '日期 Date：2026-10-15', 0, 1);

$out = __DIR__ . '/output/12_ttf_advanced.pdf';
$pdf->Output('F', $out);
echo "12_ttf_advanced.pdf 生成完成（" . filesize($out) . " 字节）\n";
