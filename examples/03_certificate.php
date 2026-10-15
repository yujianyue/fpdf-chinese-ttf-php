<?php
/**
 * 示例 3：学历证明（含证件照 + 二维码），居中排版 + 公章。
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

// ---- 标题 ----
$pdf->SetFont('GB', 'B', 28);
$pdf->Ln(8);
$pdf->Cell(0, 14, zh('学 历 证 明'), 0, 1, 'C');
$pdf->SetFont('GB', '', 12);
$pdf->Cell(0, 8, zh('CERTIFICATE  OF  STUDY'), 0, 1, 'C');
$pdf->SetDrawColor(28, 99, 169);
$pdf->Line(70, $pdf->GetY() + 1, 140, $pdf->GetY() + 1);
$pdf->Ln(8);

// ---- 证件照（绝对定位在右侧，不干扰正文流）----
$pdf->Image($A . 'photo.jpg', 150, 56, 36, 45);
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetFont('GB', '', 10);
$pdf->Cell(36, 5, zh('（一寸免冠照片）'), 0, 1, 'C');

// ---- 正文 ----
$pdf->SetFont('GB', '', 12);
$pdf->SetX(20);
$pdf->MultiCell(120, 8, zh('    兹证明 李明，男，汉族，一九九八年六月生。该生于 2016 年 9 月至 2020 年 7 月在我校计算机科学与技术专业学习，修完教学计划规定的全部课程，成绩合格，准予毕业。'));
$pdf->Ln(2);
$pdf->SetX(20);
$pdf->MultiCell(120, 8, zh('    学历层次：本科；学制：四年；毕业证书编号：100202020200123。'));
$pdf->Ln(2);
$pdf->SetX(20);
$pdf->MultiCell(120, 8, zh('    特此证明。本证书信息可通过下方二维码扫码核验。'));

// ---- 底部：二维码 + 落款 ----
$pdf->Ln(16);
$pdf->SetFont('GB', '', 11);
$pdf->SetX(20);
$pdf->Cell(0, 7, zh('毕业院校：示例大学（盖章）'), 0, 1);
$pdf->SetX(20);
$pdf->Cell(0, 7, zh('发证日期：2020 年 7 月 1 日'), 0, 1);

$pdf->Image($A . 'qrcode.png', 150, $pdf->GetY() - 6, 30, 30);
$pdf->SetFont('GB', '', 9);
$pdf->SetXY(150, $pdf->GetY() + 26);
$pdf->Cell(30, 5, zh('扫码核验真伪'), 0, 0, 'C');

// ---- 公章 ----
$pdf->Image($A . 'seal.png', 120, 215, 36, 36);

$pdf->Output('F', __DIR__ . '/output/03_certificate.pdf');
echo "03_certificate.pdf 生成完成\n";
