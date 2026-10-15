<?php
/**
 * 示例 7：学生成绩单（成绩表格 + 等级统计 + 图表）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$scores = array(
    array('语文', 118),
    array('数学', 142),
    array('英语', 131),
    array('物理', 88),
    array('化学', 76),
    array('生物', 95),
);

function grade($s) {
    if ($s >= 130) return 'A';
    if ($s >= 110) return 'B';
    if ($s >= 90)  return 'C';
    if ($s >= 60)  return 'D';
    return 'E';
}

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 20);
$pdf->Cell(0, 12, zh('学 生 成 绩 单'), 0, 1, 'C');
$pdf->SetFont('GB', '', 10);
$pdf->Cell(0, 6, zh('姓名：王小明    班级：高三（2）班    学号：2026007'), 0, 1, 'C');
$pdf->Ln(6);

// 表格：科目 / 成绩 / 等级
$header = array('科目', '成绩', '等级');
$w = array(60, 60, 60);
$pdf->SetDrawColor(150, 150, 150);
$pdf->SetFont('GB', 'B', 11);
$pdf->SetFillColor(28, 99, 169);
$pdf->SetTextColor(255, 255, 255);
foreach ($header as $i => $t) $pdf->Cell($w[$i], 9, zh($t), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('GB', '', 11);
$pdf->SetFillColor(245, 247, 250);
$fill = false;
$sum = 0;
foreach ($scores as $r) {
    $sum += $r[1];
    $pdf->Cell($w[0], 8, zh($r[0]), 1, 0, 'C', $fill);
    $pdf->Cell($w[1], 8, zh((string)$r[1]), 1, 0, 'C', $fill);
    $pdf->Cell($w[2], 8, zh(grade($r[1])), 1, 0, 'C', $fill);
    $pdf->Ln();
    $fill = !$fill;
}
$avg = round($sum / count($scores), 1);
$pdf->SetFont('GB', 'B', 11);
$pdf->Cell($w[0], 8, zh('平均分'), 1, 0, 'C');
$pdf->Cell($w[1], 8, zh((string)$avg), 1, 0, 'C');
$pdf->Cell($w[2], 8, zh(grade($avg)), 1, 1, 'C');

$pdf->Ln(4);
$pdf->SetFont('GB', '', 11);
$pdf->Cell(0, 7, zh('班级排名：第 3 名 / 共 45 人    综合评价：B+'), 0, 1);

// 图表
$pdf->Ln(4);
$pdf->SetFont('GB', 'B', 12);
$pdf->Cell(0, 7, zh('成绩分布图'), 0, 1);
$pdf->Image($A . 'chart.png', 30, $pdf->GetY(), 130, 77);

$pdf->Output('F', __DIR__ . '/output/07_reportcard.pdf');
echo "07_reportcard.pdf 生成完成\n";
