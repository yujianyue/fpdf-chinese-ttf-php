<?php
/**
 * 示例 9：收据 / 小票（窄幅 80mm + 支付二维码）。
 * 仅依赖 fpdf.php 与 chinese.php。自定义纸张尺寸通过数组 [宽, 高] 实现。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', array(80, 200)); // 自定义窄幅纸张
$pdf->AddGBFont();
$pdf->SetMargins(5, 5);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 14);
$pdf->Cell(0, 9, zh('演 示 商 店'), 0, 1, 'C');
$pdf->SetFont('GB', '', 8);
$pdf->Cell(0, 5, zh('北京市朝阳区示例路 1 号  010-8888-8888'), 0, 1, 'C');
$pdf->Cell(0, 5, zh('销售单据   NO.202610150012'), 0, 1, 'C');
$pdf->Ln(2);
$pdf->Cell(0, 0, '', 'B'); // 分隔线
$pdf->Ln(3);

$items = array(
    array('矿泉水 550ml', 2, '4.00'),
    array('面包', 1, '6.50'),
    array('牛奶 1L', 1, '12.00'),
    array('抽纸 3层', 3, '9.90'),
    array('咖啡 速溶', 2, '25.00'),
);
$pdf->SetFont('GB', '', 9);
foreach ($items as $it) {
    $pdf->Cell(40, 6, zh($it[0]), 0, 0, 'L');
    $pdf->Cell(12, 6, zh('x' . $it[1]), 0, 0, 'R');
    $pdf->Cell(18, 6, zh($it[2]), 0, 1, 'R');
}
$total = 4.00 + 6.50 + 12.00 + 9.90 + 25.00;
$pdf->Cell(0, 0, '', 'B');
$pdf->Ln(3);
$pdf->SetFont('GB', 'B', 10);
$pdf->Cell(0, 7, zh('合计：￥' . number_format($total, 2)), 0, 1, 'R');
$pdf->SetFont('GB', '', 8);
$pdf->Cell(0, 5, zh('收款方式：微信支付'), 0, 1, 'L');

// 支付二维码
$pdf->Ln(3);
$pdf->Image($A . 'qrcode.png', 25, $pdf->GetY(), 30, 30);
$pdf->Ln(30);
$pdf->SetFont('GB', '', 9);
$pdf->Cell(0, 6, zh('扫码完成支付 · 谢谢惠顾'), 0, 1, 'C');
$pdf->Cell(0, 5, zh('2026-10-15 18:32'), 0, 1, 'C');

$pdf->Output('F', __DIR__ . '/output/09_receipt.pdf');
echo "09_receipt.pdf 生成完成\n";
