<?php
/**
 * 示例 8：邀请函（整体居中排版 + 双线装饰边框）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(false, 15);
$pdf->AddPage();

// 装饰双线边框
$pdf->SetDrawColor(28, 99, 169);
$pdf->Rect(15, 15, 180, 267);
$pdf->SetDrawColor(120, 160, 210);
$pdf->Rect(19, 19, 172, 259);

$pdf->SetFont('GB', 'B', 30);
$pdf->Ln(34);
$pdf->Cell(0, 16, zh('邀 请 函'), 0, 1, 'C');

$pdf->SetFont('GB', '', 13);
$pdf->Ln(10);
$pdf->MultiCell(0, 8, zh('    谨此邀请您出席「2026 数字化转型峰会」，共同探讨云计算、安全运维与自动化监控的最新实践。'), 0, 'C');
$pdf->Ln(6);
$pdf->MultiCell(0, 8, zh('会议时间：2026 年 11 月 20 日（周五）14:00'), 0, 'C');
$pdf->MultiCell(0, 8, zh('会议地点：北京市朝阳区国际会议中心 三层报告厅'), 0, 'C');
$pdf->MultiCell(0, 8, zh('主办单位：演示科技有限公司'), 0, 'C');
$pdf->Ln(14);

$pdf->SetFont('GB', '', 12);
$pdf->MultiCell(0, 7, zh('    敬请拨冗莅临，您的参与将是我们莫大的荣幸。'), 0, 'C');
$pdf->Ln(20);
$pdf->SetFont('GB', 'B', 13);
$pdf->Cell(0, 8, zh('演示科技有限公司'), 0, 1, 'C');
$pdf->SetFont('GB', '', 11);
$pdf->Cell(0, 7, zh('2026 年 10 月 15 日'), 0, 1, 'C');

$pdf->Output('F', __DIR__ . '/output/08_invitation.pdf');
echo "08_invitation.pdf 生成完成\n";
