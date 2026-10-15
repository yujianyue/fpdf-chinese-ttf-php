<?php
/**
 * 示例 6：技术服务合同（多页条款 + 双方签名区 + 公章）。
 * 仅依赖 fpdf.php 与 chinese.php。
 */
require_once __DIR__ . '/../fpdf.php';
require_once __DIR__ . '/../chinese.php';

function zh($s) { return iconv('UTF-8', 'GBK//IGNORE', $s); }
$A = __DIR__ . '/assets/';

$pdf = new PDF_Chinese('P', 'mm', 'A4');
$pdf->AddGBFont();
$pdf->SetAutoPageBreak(true, 22);
$pdf->AddPage();

$pdf->SetFont('GB', 'B', 20);
$pdf->Ln(6);
$pdf->Cell(0, 12, zh('技 术 服 务 合 同'), 0, 1, 'C');
$pdf->SetFont('GB', '', 10);
$pdf->Cell(0, 7, zh('合同编号：HT-2026-0312    签订地点：北京市'), 0, 1, 'C');
$pdf->Ln(6);

// 打印一条“条款”：加粗小标题 + 正文段落
function article($pdf, $no, $title, $body)
{
    $pdf->SetFont('GB', 'B', 12);
    $pdf->MultiCell(0, 7, zh('第' . $no . '条  ' . $title));
    $pdf->SetFont('GB', '', 11);
    $pdf->MultiCell(0, 6.5, zh($body));
    $pdf->Ln(3);
}

$a = array(
    array('一', '服务内容', '甲方委托乙方提供 SSL 证书批量监控系统的设计与部署服务，包括但不限于域名采集、到期预警、报表导出等模块。'),
    array('二', '服务期限', '本合同服务期限为自签署之日起十二个月。期满前三十日，双方可协商续签。'),
    array('三', '费用与支付', '合同总金额为人民币 陆万元整（￥60,000.00）。甲方应于合同生效后七个工作日内支付首付款 50%，验收合格后支付尾款 50%。'),
    array('四', '双方权利义务', '乙方应保证系统稳定运行，关键接口可用性不低于 99.9%；甲方应提供必要的网络与域名访问授权。'),
    array('五', '保密条款', '双方对履约过程中知悉的对方商业秘密负有保密义务，保密期限不受合同终止影响，持续五年。'),
    array('六', '违约责任', '任何一方违反本合同约定，应向守约方支付合同总额 20% 的违约金，并赔偿由此造成的直接损失。'),
    array('七', '争议解决', '因本合同引起的争议，双方应友好协商解决；协商不成的，提交合同签订地有管辖权的人民法院诉讼解决。'),
    array('八', '其他约定', '本合同一式两份，甲乙双方各执一份，自双方签字盖章之日起生效。未尽事宜，由双方另行签订补充协议。'),
);
foreach ($a as $x) {
    article($pdf, $x[0], $x[1], $x[2]);
}

// 签署区
$pdf->Ln(4);
$pdf->SetFont('GB', 'B', 12);
$pdf->MultiCell(0, 8, zh('甲方（盖章）：________________    乙方（盖章）：________________'));

// 双方签名 + 公章（绝对定位）
$y = $pdf->GetY() + 4;
$pdf->Image($A . 'seal.png', 28, $y, 30, 30);
$pdf->Image($A . 'seal.png', 130, $y, 30, 30);
$pdf->Image($A . 'signature.png', 120, $y + 18, 46, 15);
$pdf->SetFont('GB', '', 10);
$pdf->SetY($y + 32);
$pdf->Cell(0, 6, zh('甲方代表签字：____________        乙方代表签字：____________'), 0, 1);

$pdf->Output('F', __DIR__ . '/output/06_contract.pdf');
echo "06_contract.pdf 生成完成\n";
