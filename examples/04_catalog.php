<?php
/**
 * 示例 4：产品目录（双栏排版 + 产品图片）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 22);
$pdf->Cell(0, 12, zh('产 品 目 录'), 0, 1, 'C');
$pdf->SetFont('GB', '', 10);
$pdf->Cell(0, 7, zh('2026 春季新品 · 价格单位为元'), 0, 1, 'C');
$pdf->Ln(4);

$products = array(
    array('product1.png', '企业路由器', '299.00', '全千兆端口，企业级稳定组网'),
    array('product2.png', '核心交换机', '899.00', '48 口 PoE，智能流量调度'),
    array('product3.png', '无线 AP',    '199.00', 'Wi-Fi 6，高密度接入'),
    array('product4.png', '网络安全网关', '1299.00', '一体化防护，威胁实时拦截'),
);

$pdf->SetDrawColor(200, 200, 200);
$startY = $pdf->GetY();
$cardW = 88; $cardH = 46; $gap = 6;
foreach ($products as $i => $p) {
    $col = $i % 2;
    $row = intval($i / 2);
    $x = 12 + $col * ($cardW + $gap);
    $y = $startY + $row * ($cardH + $gap);
    // 卡片边框
    $pdf->Rect($x, $y, $cardW, $cardH);
    // 产品图片（绝对定位，不干扰文字流）
    $pdf->Image($A . $p[0], $x + 4, $y + 6, 34, 25);
    // 文字信息
    $pdf->SetFont('GB', 'B', 12);
    $pdf->SetXY($x + 42, $y + 6);
    $pdf->Cell($cardW - 44, 7, zh($p[1]), 0, 2, 'L');
    $pdf->SetFont('GB', '', 11);
    $pdf->SetTextColor(200, 60, 30);
    $pdf->Cell($cardW - 44, 7, zh('￥' . $p[2]), 0, 2, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('GB', '', 9);
    $pdf->MultiCell($cardW - 44, 5, zh($p[3]));
}

$pdf->SetXY(12, $startY + 2 * ($cardH + $gap) + 4);
$pdf->SetFont('GB', '', 9);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell(0, 6, zh('* 本目录价格仅供参考，最终以合同为准。'), 0, 1);

$pdf->Output('F', __DIR__ . '/output/04_catalog.pdf');
echo "04_catalog.pdf 生成完成\n";
