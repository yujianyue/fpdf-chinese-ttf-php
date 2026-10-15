<?php
/**
 * 示例 5：在职证明（正式信函 + 手写签名图片 + 公司公章）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 24);
$pdf->Ln(10);
$pdf->Cell(0, 14, zh('在 职 证 明'), 0, 1, 'C');
$pdf->SetDrawColor(28, 99, 169);
$pdf->Line(75, $pdf->GetY() + 1, 135, $pdf->GetY() + 1);
$pdf->Ln(12);

$pdf->SetFont('GB', '', 12);
$body = '    兹证明 张三（性别：男，身份证号：1101011990********），系我单位（演示科技有限公司）正式员工。'
      . '该同志自 2019 年 3 月起在我公司 技术研发部 担任 高级工程师 职务，已连续工作满六年，目前仍在职，薪资属 confidential 范围。'
      . '其人事档案由本单位统一管理。'
      . "\n\n    该证明仅用于 办理住房贷款 之用，不作其他用途，特此证明。";
$pdf->MultiCell(0, 8, zh($body));
$pdf->Ln(10);

$pdf->SetFont('GB', '', 12);
$pdf->Cell(0, 8, zh('单位名称：演示科技有限公司'), 0, 1);
$pdf->Cell(0, 8, zh('单位地址：北京市朝阳区示例路 1 号'), 0, 1);
$pdf->Cell(0, 8, zh('联系电话：010-8888-8888'), 0, 1);
$pdf->Cell(0, 8, zh('证明日期：2026 年 10 月 15 日'), 0, 1);

// 签名图片（绝对定位在落款右侧）
$pdf->Image($A . 'signature.png', 135, $pdf->GetY() - 22, 50, 16);
// 公章
$pdf->Image($A . 'seal.png', 130, $pdf->GetY() - 4, 36, 36);

$pdf->Output('F', __DIR__ . '/output/05_employment.pdf');
echo "05_employment.pdf 生成完成\n";
