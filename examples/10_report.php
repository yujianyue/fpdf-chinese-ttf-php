<?php
/**
 * 示例 10：季度数据报表（图表图片 + 文字分析 + 汇总表）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 20);
$pdf->Cell(0, 12, zh('季 度 数 据 报 表'), 0, 1, 'C');
$pdf->SetFont('GB', '', 10);
$pdf->Cell(0, 6, zh('统计周期：2026 年第三季度（7-9 月）    制表日期：2026-10-15'), 0, 1, 'C');
$pdf->Ln(4);

// 图表（绝对定位后需手动把光标移到图片下方，避免与后续文字重叠）
$imgY = $pdf->GetY();
$pdf->Image($A . 'chart.png', 25, $imgY, 150, 88);
$pdf->SetY($imgY + 88 + 5);

// 文字分析
$pdf->SetFont('GB', '', 11);
$pdf->MultiCell(0, 6.5, zh('    本季度销售额整体呈上升态势：7 月为 120 万元，8 月小幅回落至 135 万元，'
  . '9 月在“金九”促销带动下升至 210 万元，创年内新高。环比来看，第三季度合计 645 万元，'
  . '较第二季度的 520 万元增长约 24%。建议四季度加大库存备货与渠道投放，稳固增长曲线。'));
$pdf->Ln(4);

// 汇总表
$pdf->SetFont('GB', 'B', 12);
$pdf->Cell(0, 7, zh('关键指标汇总'), 0, 1);
$header = array('指标', '本期', '同比');
$w = array(70, 50, 50);
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetFont('GB', 'B', 11);
$pdf->SetFillColor(28, 99, 169);
$pdf->SetTextColor(255, 255, 255);
foreach ($header as $i => $t) $pdf->Cell($w[$i], 9, zh($t), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('GB', '', 11);
$pdf->SetFillColor(245, 247, 250);
$rows = array(
    array('销售额（万元）', '645', '+24%'),
    array('订单量（万单）', '18.6', '+15%'),
    array('客单价（元）', '347', '+8%'),
    array('退货率', '1.8%', '-0.4pt'),
);
$fill = false;
foreach ($rows as $r) {
    $pdf->Cell($w[0], 8, zh($r[0]), 1, 0, 'L', $fill);
    $pdf->Cell($w[1], 8, zh($r[1]), 1, 0, 'C', $fill);
    $pdf->Cell($w[2], 8, zh($r[2]), 1, 0, 'C', $fill);
    $pdf->Ln();
    $fill = !$fill;
}

$pdf->Output('F', __DIR__ . '/output/10_report.pdf');
echo "10_report.pdf 生成完成\n";
