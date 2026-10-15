<?php
/**
 * 示例 1：单页报价单（含表格 + 公司 Logo 图片）
 * 仅依赖 fpdf.php 与 chinese.php，通过 AddGBFont 渲染中文。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

// UTF-8 中文 -> GBK，供 PDF_Chinese(GBKp-EUC-H) 正确输出中文
function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }

$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 16);
$pdf->AddPage();

// ---- 页眉：Logo + 标题 + 单号 ----
$pdf->Image($A . 'logo.png', 12, 10, 44);
$pdf->SetXY(62, 12);
$pdf->SetFont('GB', 'B', 20);
$pdf->Cell(0, 10, zh('报 价 单'), 0, 1, 'L');
$pdf->SetFont('GB', '', 10);
$pdf->SetXY(62, 24);
$pdf->Cell(0, 6, zh('演示科技有限公司'), 0, 1, 'L');
$pdf->SetXY(62, 30);
$pdf->Cell(0, 6, zh('报价单号：QT-2026-001    日期：2026-10-15'), 0, 1, 'L');
$pdf->Line(12, 40, 198, 40);
$pdf->Ln(10);

// ---- 客户信息 ----
$pdf->SetFont('GB', '', 11);
$pdf->Cell(0, 7, zh('客户名称：示例客户有限公司'), 0, 1);
$pdf->Cell(0, 7, zh('联系电话：138-0000-0000'), 0, 1);
$pdf->Cell(0, 7, zh('交付地址：北京市朝阳区示例路 1 号'), 0, 1);
$pdf->Ln(4);

// ---- 商品表格 ----
$header = array('序号', '商品名称', '规格', '数量', '单价(元)', '金额(元)');
$rows = array(
    array('1', '企业级路由器', 'RG-2000', '2', '1200.00', '2400.00'),
    array('2', '千兆交换机',   'SW-48P', '1', '3500.00', '3500.00'),
    array('3', '网络机柜',     '42U',    '1', '1800.00', '1800.00'),
    array('4', '光纤模块',     '10G-SR', '10','260.00',  '2600.00'),
);
$w = array(14, 52, 34, 18, 30, 32);

$pdf->SetDrawColor(150, 150, 150);
$pdf->SetFont('GB', 'B', 10);
$pdf->SetFillColor(28, 99, 169);
$pdf->SetTextColor(255, 255, 255);
foreach ($header as $i => $t) {
    $pdf->Cell($w[$i], 9, zh($t), 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('GB', '', 10);
$pdf->SetFillColor(245, 247, 250);
$fill = false;
foreach ($rows as $r) {
    $pdf->Cell($w[0], 9, zh($r[0]), 1, 0, 'C', $fill);
    $pdf->Cell($w[1], 9, zh($r[1]), 1, 0, 'L', $fill);
    $pdf->Cell($w[2], 9, zh($r[2]), 1, 0, 'C', $fill);
    $pdf->Cell($w[3], 9, zh($r[3]), 1, 0, 'C', $fill);
    $pdf->Cell($w[4], 9, zh($r[4]), 1, 0, 'R', $fill);
    $pdf->Cell($w[5], 9, zh($r[5]), 1, 0, 'R', $fill);
    $pdf->Ln();
    $fill = !$fill;
}

// 合计行
$total = 2400 + 3500 + 1800 + 2600;
$pdf->SetFont('GB', 'B', 11);
$pdf->Cell(array_sum($w) - 32, 9, zh('合  计'), 1, 0, 'C');
$pdf->Cell(32, 9, zh(number_format($total, 2)), 1, 1, 'R');

// ---- 说明 + 签章 ----
$pdf->Ln(6);
$pdf->SetFont('GB', '', 10);
$pdf->MultiCell(0, 6, zh('说明：以上报价含 13% 增值税专用发票，含运费，交货周期 7 个工作日。本报价单自签发之日起 30 天内有效。'));
$pdf->Ln(2);
$pdf->Cell(0, 7, zh('甲方（盖章）：________________    乙方（盖章）：演示科技有限公司'), 0, 1);

$pdf->Output('F', __DIR__ . '/output/01_quote.pdf');
echo "01_quote.pdf 生成完成\n";
