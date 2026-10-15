<?php
// 改进作者: yujianyue <15058593138@qq.com>
//
// ----------------------------------------------------------------------------
// 兼容性修复版（PHP 5.5+ 全版本兼容，已验证 PHP 8.3 零告警）
// 修复内容：
//  1) GetStringWidth/MultiCell/Write 访问 $this->CurrentFont['type'] 前增加
//     isset 守卫，避免未设字体时“对 null 取数组下标”致命错误；
//  2) MultiCell 默认参数 $fill 改为 false，与父类签名保持一致（LSP 兼容）；
//  3) MBMultiCell 边框判断 is_int(strpos(...)) 改为 !== false，语义更清晰；
//  4) AddBig5hwFont / AddGBhwFont 循环前初始化 $cw = array()，消除未定义变量警告；
//  5) MBMultiCell $fill 默认值统一为 false。
// 详细说明见随附《说明书.md》。
// ----------------------------------------------------------------------------

$Big5_widths = array(' '=>250,'!'=>250,'"'=>408,'#'=>668,'$'=>490,'%'=>875,'&'=>698,'\''=>250,
 '('=>240,')'=>240,'*'=>417,'+'=>667,','=>250,'-'=>313,'.'=>250,'/'=>520,'0'=>500,'1'=>500,
 '2'=>500,'3'=>500,'4'=>500,'5'=>500,'6'=>500,'7'=>500,'8'=>500,'9'=>500,':'=>250,';'=>250,
 '<'=>667,'='=>667,'>'=>667,'?'=>396,'@'=>921,'A'=>677,'B'=>615,'C'=>719,'D'=>760,'E'=>625,
 'F'=>552,'G'=>771,'H'=>802,'I'=>354,'J'=>354,'K'=>781,'L'=>604,'M'=>927,'N'=>750,'O'=>823,
 'P'=>563,'Q'=>823,'R'=>729,'S'=>542,'T'=>698,'U'=>771,'V'=>729,'W'=>948,'X'=>771,'Y'=>677,
 'Z'=>635,'['=>344,'\\'=>520,']'=>344,'^'=>469,'_'=>500,'`'=>250,'a'=>469,'b'=>521,'c'=>427,
 'd'=>521,'e'=>438,'f'=>271,'g'=>469,'h'=>531,'i'=>250,'j'=>250,'k'=>458,'l'=>240,'m'=>802,
 'n'=>531,'o'=>500,'p'=>521,'q'=>521,'r'=>365,'s'=>333,'t'=>292,'u'=>521,'v'=>458,'w'=>677,
 'x'=>479,'y'=>458,'z'=>427,'{'=>480,'|'=>496,'}'=>480,'~'=>667);

$GB_widths = array(' '=>207,'!'=>270,'"'=>342,'#'=>467,'$'=>462,'%'=>797,'&'=>710,'\''=>239,
 '('=>374,')'=>374,'*'=>423,'+'=>605,','=>238,'-'=>375,'.'=>238,'/'=>334,'0'=>462,'1'=>462,
 '2'=>462,'3'=>462,'4'=>462,'5'=>462,'6'=>462,'7'=>462,'8'=>462,'9'=>462,':'=>238,';'=>238,
 '<'=>605,'='=>605,'>'=>605,'?'=>344,'@'=>748,'A'=>684,'B'=>560,'C'=>695,'D'=>739,'E'=>563,
 'F'=>511,'G'=>729,'H'=>793,'I'=>318,'J'=>312,'K'=>666,'L'=>526,'M'=>896,'N'=>758,'O'=>772,
 'P'=>544,'Q'=>772,'R'=>628,'S'=>465,'T'=>607,'U'=>753,'V'=>711,'W'=>972,'X'=>647,'Y'=>620,
 'Z'=>607,'['=>374,'\\'=>333,']'=>374,'^'=>606,'_'=>500,'`'=>239,'a'=>417,'b'=>503,'c'=>427,
 'd'=>529,'e'=>415,'f'=>264,'g'=>444,'h'=>518,'i'=>241,'j'=>230,'k'=>495,'l'=>228,'m'=>793,
 'n'=>527,'o'=>524,'p'=>524,'q'=>504,'r'=>338,'s'=>336,'t'=>277,'u'=>517,'v'=>450,'w'=>652,
 'x'=>466,'y'=>452,'z'=>407,'{'=>370,'|'=>258,'}'=>370,'~'=>605);

require_once __DIR__.'/ttf.php';

class PDF_Chinese extends FPDF
{
 var $_ttfFragments = array();   // 两遍渲染：文本占位符片段（id => array('fontkey','cp')）
 var $_ttfFinalized = false;     // 是否已执行子集化与占位符回填
function AddCIDFont($family, $style, $name, $cw, $CMap, $registry)
{
 $fontkey = strtolower($family).strtoupper($style);
 if(isset($this->fonts[$fontkey]))
  $this->Error("Font already added: $family $style");
 $i = count($this->fonts)+1;
 $name = str_replace(' ','',$name);
 $this->fonts[$fontkey] = array('i'=>$i, 'type'=>'Type0', 'name'=>$name, 'up'=>-130, 'ut'=>40, 'cw'=>$cw, 'CMap'=>$CMap, 'registry'=>$registry);
}

function AddCIDFonts($family, $name, $cw, $CMap, $registry)
{
 $this->AddCIDFont($family,'',$name,$cw,$CMap,$registry);
 $this->AddCIDFont($family,'B',$name.',Bold',$cw,$CMap,$registry);
 $this->AddCIDFont($family,'I',$name.',Italic',$cw,$CMap,$registry);
 $this->AddCIDFont($family,'BI',$name.',BoldItalic',$cw,$CMap,$registry);
}

function AddBig5Font($family='Big5', $name='MSungStd-Light-Acro')
{
 //添加 Big5 字体（Latin 字符为比例宽度）
 $cw = $GLOBALS['Big5_widths'];
 $CMap = 'ETenms-B5-H';
 $registry = array('ordering'=>'CNS1', 'supplement'=>0);
 $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
}

function AddBig5hwFont($family='Big5-hw', $name='MSungStd-Light-Acro')
{
 //添加 Big5 字体（Latin 字符为半角等宽）
 $cw = array();
 for($i=32;$i<=126;$i++)
  $cw[chr($i)] = 500;
 $CMap = 'ETen-B5-H';
 $registry = array('ordering'=>'CNS1', 'supplement'=>0);
 $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
}

function AddGBFont($family='GB', $name='STSongStd-Light-Acro')
{
 //添加 GB 字体（Latin 字符为比例宽度）
 $cw = $GLOBALS['GB_widths'];
 $CMap = 'GBKp-EUC-H';
 $registry = array('ordering'=>'GB1', 'supplement'=>2);
 $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
}

function AddGBhwFont($family='GB-hw', $name='STSongStd-Light-Acro')
{
 //添加 GB 字体（Latin 字符为半角等宽）
 $cw = array();
 for($i=32;$i<=126;$i++)
  $cw[chr($i)] = 500;
 $CMap = 'GBK-EUC-H';
 $registry = array('ordering'=>'GB1', 'supplement'=>2);
 $this->AddCIDFonts($family,$name,$cw,$CMap,$registry);
}

function GetStringWidth($s)
{
 if(isset($this->CurrentFont) && $this->CurrentFont['type']=='Type0')
  return $this->GetMBStringWidth($s);
 elseif(isset($this->CurrentFont) && $this->CurrentFont['type']=='TTF')
  return $this->_utf8StringWidth($s);
 else
  return parent::GetStringWidth($s);
}

function GetMBStringWidth($s)
{
 //GetStringWidth 的多字节（中文）版本
 $l = 0;
 $cw = &$this->CurrentFont['cw'];
 $nb = strlen($s);
 $i = 0;
 while($i<$nb)
 {
  $c = $s[$i];
  if(ord($c)<128)
  {
  $l += $cw[$c];
  $i++;
  }
  else
  {
  $l += 1000;
  $i += 2;
  }
 }
 return $l*$this->FontSize/1000;
}

function MultiCell($w, $h, $txt, $border=0, $align='L', $fill=false)
{
 if(isset($this->CurrentFont)) {
  if($this->CurrentFont['type']=='Type0')
   $this->MBMultiCell($w,$h,$txt,$border,$align,$fill);
  elseif($this->CurrentFont['type']=='TTF')
   $this->_utf8MultiCell($w,$h,$txt,$border,$align,$fill);
  else
   parent::MultiCell($w,$h,$txt,$border,$align,$fill);
 } else
  parent::MultiCell($w,$h,$txt,$border,$align,$fill);
}

function MBMultiCell($w, $h, $txt, $border=0, $align='L', $fill=false)
{
 //MultiCell 的多字节（中文）版本
 $cw = &$this->CurrentFont['cw'];
 if($w==0)
  $w = $this->w-$this->rMargin-$this->x;
 $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
 $s = str_replace("\r",'',$txt);
 $nb = strlen($s);
 if($nb>0 && $s[$nb-1]=="\n")
  $nb--;
 $b = 0;
 if($border)
 {
  if($border==1)
  {
  $border = 'LTRB';
  $b = 'LRT';
  $b2 = 'LR';
  }
  else
  {
  $b2 = '';
  if(strpos($border,'L')!==false)
  $b2 .= 'L';
  if(strpos($border,'R')!==false)
  $b2 .= 'R';
  $b = strpos($border,'T')!==false ? $b2.'T' : $b2;
  }
 }
 $sep = -1;
 $i = 0;
 $j = 0;
 $l = 0;
 $nl = 1;
 while($i<$nb)
 {
  //获取下一个字符
  $c = $s[$i];
  //判断当前字符是 ASCII 还是多字节
  $ascii = (ord($c)<128);
  if($c=="\n")
  {
  //显式换行（遇到 \n）
  $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
  $i++;
  $sep = -1;
  $j = $i;
  $l = 0;
  $nl++;
  if($border && $nl==2)
  $b = $b2;
  continue;
  }
  if(!$ascii)
  {
  $sep = $i;
  $ls = $l;
  }
  elseif($c==' ')
  {
  $sep = $i;
  $ls = $l;
  }
  $l += $ascii ? $cw[$c] : 1000;
  if($l>$wmax)
  {
  //自动换行（超出宽度时）
  if($sep==-1 || $i==$j)
  {
  if($i==$j)
  $i += $ascii ? 1 : 2;
  $this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
  }
  else
  {
  // 空格分隔符不并入本行；非空格分隔符（GBK 汉字 2 字节）并入本行并跳过，
  // 避免回退到同一分隔符造成死循环
  if($s[$sep]==' ')
  {
   $this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
   $i = $sep+1;
  }
  else
  {
   $this->Cell($w,$h,substr($s,$j,$sep-$j+2),$b,2,$align,$fill);
   $i = $sep+2;
  }
  }
  $sep = -1;
  $j = $i;
  $l = 0;
  $nl++;
  if($border && $nl==2)
  $b = $b2;
 }
 else
  $i += $ascii ? 1 : 2;
}
//输出最后一段剩余文本
if($border && strpos($border,'B')!==false)
 $b .= 'B';
$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
$this->x = $this->lMargin;
}

function Write($h, $txt, $link='')
{
 if(isset($this->CurrentFont)) {
  if($this->CurrentFont['type']=='Type0')
   $this->MBWrite($h,$txt,$link);
  elseif($this->CurrentFont['type']=='TTF')
   $this->_utf8Write($h,$txt,$link);
  else
   parent::Write($h,$txt,$link);
 } else
  parent::Write($h,$txt,$link);
}

function MBWrite($h, $txt, $link)
{
 //Write 的多字节（中文）版本
 $cw = &$this->CurrentFont['cw'];
 $w = $this->w-$this->rMargin-$this->x;
 $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
 $s = str_replace("\r",'',$txt);
 $nb = strlen($s);
 $sep = -1;
 $i = 0;
 $j = 0;
 $l = 0;
 $nl = 1;
 while($i<$nb)
 {
  //获取下一个字符
  $c = $s[$i];
  //判断当前字符是 ASCII 还是多字节
  $ascii = (ord($c)<128);
  if($c=="\n")
  {
  //显式换行（遇到 \n）
  $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
  $i++;
  $sep = -1;
  $j = $i;
  $l = 0;
  if($nl==1)
  {
  $this->x = $this->lMargin;
  $w = $this->w-$this->rMargin-$this->x;
  $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
  }
  $nl++;
  continue;
  }
  if(!$ascii || $c==' ')
  $sep = $i;
  $l += $ascii ? $cw[$c] : 1000;
  if($l>$wmax)
  {
  //自动换行（超出宽度时）
  if($sep==-1 || $i==$j)
  {
  if($this->x>$this->lMargin)
  {
  //移动到下一行
  $this->x = $this->lMargin;
  $this->y += $h;
  $w = $this->w-$this->rMargin-$this->x;
  $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
  $i++;
  $nl++;
  continue;
  }
  if($i==$j)
  $i += $ascii ? 1 : 2;
  $this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',0,$link);
  }
  else
  {
  // 同 MBMultiCell：非空格分隔符（GBK 汉字 2 字节）并入本行并跳过，防止死循环
  if($s[$sep]==' ')
  {
   $this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',0,$link);
   $i = $sep+1;
  }
  else
  {
   $this->Cell($w,$h,substr($s,$j,$sep-$j+2),0,2,'',0,$link);
   $i = $sep+2;
  }
  }
  $sep = -1;
  $j = $i;
  $l = 0;
  if($nl==1)
  {
  $this->x = $this->lMargin;
  $w = $this->w-$this->rMargin-$this->x;
  $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
  }
  $nl++;
  }
  else
  $i += $ascii ? 1 : 2;
 }
 //输出最后一段剩余文本
 if($i!=$j)
  $this->Cell($l/1000*$this->FontSize,$h,substr($s,$j,$i-$j),0,0,'',0,$link);
}

function _putType0($font)
{
 //Type0 复合字体
 $this->_newobj();
 $this->_out('<</Type /Font');
 $this->_out('/Subtype /Type0');
 $this->_out('/BaseFont /'.$font['name'].'-'.$font['CMap']);
 $this->_out('/Encoding /'.$font['CMap']);
 $this->_out('/DescendantFonts ['.($this->n+1).' 0 R]');
 $this->_out('>>');
 $this->_out('endobj');
 //CIDFont  descendant 字体
 $this->_newobj();
 $this->_out('<</Type /Font');
 $this->_out('/Subtype /CIDFontType0');
 $this->_out('/BaseFont /'.$font['name']);
 $this->_out('/CIDSystemInfo <</Registry '.$this->_textstring('Adobe').' /Ordering '.$this->_textstring($font['registry']['ordering']).' /Supplement '.$font['registry']['supplement'].'>>');
 $this->_out('/FontDescriptor '.($this->n+1).' 0 R');
 if($font['CMap']=='ETen-B5-H')
  $W = '13648 13742 500';
 elseif($font['CMap']=='GBK-EUC-H')
  $W = '814 907 500 7716 [500]';
 else
  //$W = '1 ['.implode(' ',$font['cw']).']'; //该写法在 cw 为空数组时会报错，已用下行的安全写法替代
  $W = '1 ['.(!empty($font['cw']) ? implode(' ', $font['cw']) : '').']';
 $this->_out('/W ['.$W.']>>');
 $this->_out('endobj');
 //字体描述符（FontDescriptor）
 $this->_newobj();
 $this->_out('<</Type /FontDescriptor');
 $this->_out('/FontName /'.$font['name']);
 $this->_out('/Flags 6');
 $this->_out('/FontBBox [0 -200 1000 900]');
 $this->_out('/ItalicAngle 0');
 $this->_out('/Ascent 800');
 $this->_out('/Descent -200');
 $this->_out('/CapHeight 800');
 $this->_out('/StemV 50');
 $this->_out('>>');
 $this->_out('endobj');
}

// ============================================================================
// UTF-8 + TrueType/OpenType 内嵌字体支持（不再依赖阅读器端 Adobe 亚洲字体）
// ----------------------------------------------------------------------------
// 设计要点：
//  1) AddFontTTF / AddFontFromStream 加载字体，解析轮廓（glyf / CFF）并建立
//     codepoint→glyph 映射；默认 UTF-8 输入输出，无需手动 iconv。
//  2) 采用 Identity-H 编码，文本以“字形 id 的 2 字节大端”写入内容流。
//  3) 两遍渲染：文档生成时 _escape() 把文本换成占位符 XTTF#X 并记录用到的
//     码点；Output 前 _finalizeTTFonts() 子集化并建立紧凑映射，再回填文本。
//  4) 字符串处理统一按“码点（grapheme）”计算宽度与断行，支持 1–4 字节
//     （含 emoji），CJK 逐字换行、Latin 按空格换行。
// ============================================================================

// 添加 TTF/OTF 字体（从文件）
function AddFontTTF($family, $style='', $file='', $subset=true)
{
 $data = file_get_contents($file);
 if($data===false || $data==='')
  $this->Error('无法读取字体文件：'.$file);
 return $this->_registerTTF($family, $style, $data, $subset);
}

// 添加 TTF/OTF 字体（从内存数据）
function AddFontFromStream($family, $style='', $data='', $subset=true)
{
 if($data==='')
  $this->Error('字体数据为空');
 return $this->_registerTTF($family, $style, $data, $subset);
}

protected function _registerTTF($family, $style, $data, $subset)
{
 if(!class_exists('TTFontSubsetter', false))
  require_once __DIR__.'/ttf.php';
 $p = new TTFontSubsetter();
 if(!$p->load($data, 0))
  $this->Error('字体文件无效或不受支持（需 TrueType/OpenType）');
 $family = strtolower($family);
 $style = strtoupper($style);
 if($style=='IB')
  $style = 'BI';
 $fontkey = $family.$style;
 if(isset($this->fonts[$fontkey]))
  return;
 $i = count($this->fonts)+1;
 $name = preg_replace('/[^A-Za-z0-9]/', '', $p->postScriptName);
 if($name=='')
  $name = 'EmbeddedFont';
 $this->fonts[$fontkey] = array(
  'i'=>$i, 'type'=>'TTF', 'name'=>$name, 'up'=>-130, 'ut'=>40,
  'parser'=>$p, 'used'=>array(), 'subset'=>$subset,
 );
}

// UTF-8 字符串 -> 码点数组（纯 PHP，无扩展依赖，支持 1–4 字节）
protected function _utf8Codepoints($s)
{
 $cp = array();
 $len = strlen($s);
 $i = 0;
 while($i < $len)
 {
  $o = ord($s[$i]);
  if($o < 0x80)
   { $cp[] = $o; $i++; }
  elseif(($o & 0xE0) == 0xC0)
   { $cp[] = (($o & 0x1F) << 6) | (ord($s[$i+1]) & 0x3F); $i += 2; }
  elseif(($o & 0xF0) == 0xE0)
   { $cp[] = (($o & 0x0F) << 12) | ((ord($s[$i+1]) & 0x3F) << 6) | (ord($s[$i+2]) & 0x3F); $i += 3; }
  elseif(($o & 0xF8) == 0xF0)
   { $cp[] = (($o & 0x07) << 18) | ((ord($s[$i+1]) & 0x3F) << 12) | ((ord($s[$i+2]) & 0x3F) << 6) | (ord($s[$i+3]) & 0x3F); $i += 4; }
  else
   { $cp[] = $o; $i++; }
 }
 return $cp;
}

// UTF-8 字符串显示宽度（用户单位）
protected function _utf8StringWidth($s)
{
 $p = $this->CurrentFont['parser'];
 $cp = $this->_utf8Codepoints($s);
 $w = 0;
 foreach($cp as $c)
 {
  $g = $p->glyphIndex($c);
  list($a, $l) = $p->advance($g);
  $w += $a;
 }
 return $w / $p->unitsPerEm * $this->FontSize;
}

// 覆盖 _escape：TTF 模式下输出占位符 XTTF#X 并收集用到的码点（两遍渲染）
protected function _escape($s)
{
 if(isset($this->CurrentFont) && $this->CurrentFont['type']=='TTF' && !$this->_ttfFinalized)
 {
  $cp = $this->_utf8Codepoints($s);
  $fk = $this->FontFamily.$this->FontStyle;
  if(isset($this->fonts[$fk]))
  {
   foreach($cp as $c)
    $this->fonts[$fk]['used'][$c] = true;
  }
  $id = count($this->_ttfFragments);
  $this->_ttfFragments[$id] = array('fontkey'=>$fk, 'cp'=>$cp);
  return 'XTTF'.$id.'X';
 }
 return parent::_escape($s);
}

// 基于码点（UTF-8）的自动换行多行文本（支持 CJK 逐字换行、Latin 按空格换行）
protected function _utf8MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
 $p = $this->CurrentFont['parser'];
 $upem = $p->unitsPerEm;
 if($w==0)
  $w = $this->w-$this->rMargin-$this->x;
 $wmax = ($w - 2*$this->cMargin);   // 用户单位可用宽度
 $s = str_replace("\r", '', $txt);
 $cp = $this->_utf8Codepoints($s);
 $nb = count($cp);
 // 边框处理
 $b = 0;
 if($border)
 {
  if($border==1)
   { $border = 'LTRB'; $b = 'LRT'; $b2 = 'LR'; }
  else
   {
   $b2 = '';
   if(strpos($border,'L')!==false) $b2 .= 'L';
   if(strpos($border,'R')!==false) $b2 .= 'R';
   $b = (strpos($border,'T')!==false) ? $b2.'T' : $b2;
   }
 }
 $sep = -1; $i = 0; $j = 0; $lw = 0; $nl = 1;
 while($i < $nb)
 {
  $c = $cp[$i];
  if($c==0x0A)
  {
   // 显式换行（遇到 \n）
   $this->Cell($w, $h, $this->_cpSub($cp, $j, $i-$j), $b, 2, $align, $fill);
   $i++; $sep = -1; $j = $i; $lw = 0; $nl++;
   if($border && $nl==2) $b = $b2;
   continue;
  }
  if($c==0x20)
   $sep = $i;
  elseif($this->_isBreakable($c))
   $sep = $i;   // CJK / 全角 / emoji 等允许在其后断行
  $lw += $this->_cpAdvance($p, $upem, $c);
  if($lw > $wmax)
  {
   if($sep==-1 || $i==$j)   // 当前词/字过长
   {
    if($i==$j) $i++;
    $this->Cell($w, $h, $this->_cpSub($cp, $j, $i-$j), $b, 2, $align, $fill);
   }
   else
   {
    // 输出 j..sep（分隔字符并入本行），从 sep+1 继续。
    // 不可写回退式 $i=$sep：当断点与下次断点之间是一段不可断的
    // ASCII（如长 URL/路径）时，$i 会永远跳回同一个 sep 造成死循环，
    // 页数与内存随之爆炸。
    $this->Cell($w, $h, $this->_cpSub($cp, $j, $sep - $j + 1), $b, 2, $align, $fill);
    $i = $sep + 1;
   }
   $sep = -1; $j = $i; $lw = 0; $nl++;
   if($border && $nl==2) $b = $b2;
  }
  else
   $i++;
 }
 if($border && strpos($border,'B')!==false)
  $b .= 'B';
 $this->Cell($w, $h, $this->_cpSub($cp, $j, $i-$j), $b, 2, $align, $fill);
 $this->x = $this->lMargin;
}

// 基于码点（UTF-8）的流式输出（自动换行）
protected function _utf8Write($h, $txt, $link='')
{
 $p = $this->CurrentFont['parser'];
 $upem = $p->unitsPerEm;
 $w = $this->w - $this->rMargin - $this->x;
 $wmax = ($w - 2*$this->cMargin);
 $s = str_replace("\r", '', $txt);
 $cp = $this->_utf8Codepoints($s);
 $nb = count($cp);
 $sep = -1; $i = 0; $j = 0; $lw = 0; $nl = 1;
 while($i < $nb)
 {
  $c = $cp[$i];
  if($c==0x0A)
  {
   $this->Cell($w, $h, $this->_cpSub($cp, $j, $i-$j), 0, 2, '', false, $link);
   $i++; $sep = -1; $j = $i; $lw = 0;
   if($nl==1)
   {
    $this->x = $this->lMargin;
    $w = $this->w - $this->rMargin - $this->x;
    $wmax = ($w - 2*$this->cMargin);
   }
   $nl++;
   continue;
  }
  if($c==0x20 || $this->_isBreakable($c))
   $sep = $i;
  $lw += $this->_cpAdvance($p, $upem, $c);
  if($lw > $wmax)
  {
   if($sep==-1 || $i==$j)
   {
    if($this->x > $this->lMargin)
    {
     // 移动到下一行起始
     $this->x = $this->lMargin;
     $this->y += $h;
     $w = $this->w - $this->rMargin - $this->x;
     $wmax = ($w - 2*$this->cMargin);
     $i++; $nl++;
     continue;
    }
    if($i==$j) $i++;
    $this->Cell($w, $h, $this->_cpSub($cp, $j, $i-$j), 0, 2, '', false, $link);
   }
   else
   {
    // 同 _utf8MultiCell：分隔字符并入本行并跳过，避免回退死循环
    $this->Cell($w, $h, $this->_cpSub($cp, $j, $sep-$j+1), 0, 2, '', false, $link);
    $i = $sep + 1;
   }
   $sep = -1; $j = $i; $lw = 0;
   if($nl==1)
   {
    $this->x = $this->lMargin;
    $w = $this->w - $this->rMargin - $this->x;
    $wmax = ($w - 2*$this->cMargin);
   }
   $nl++;
  }
  else
   $i++;
 }
 if($i != $j)
  $this->Cell($lw, $h, $this->_cpSub($cp, $j, $i-$j), 0, 0, '', false, $link);
}

// 码点数组 -> UTF-8 子串
protected function _cpSub($cp, $from, $len)
{
 $s = '';
 for($k=$from; $k<$from+$len; $k++)
  $s .= $this->_utf8Chr($cp[$k]);
 return $s;
}

// 码点 -> UTF-8 字符
protected function _utf8Chr($c)
{
 if($c < 0x80)
  return chr($c);
 elseif($c < 0x800)
  return chr(0xC0 | ($c >> 6)).chr(0x80 | ($c & 0x3F));
 elseif($c < 0x10000)
  return chr(0xE0 | ($c >> 12)).chr(0x80 | (($c >> 6) & 0x3F)).chr(0x80 | ($c & 0x3F));
 else
  return chr(0xF0 | ($c >> 18)).chr(0x80 | (($c >> 12) & 0x3F)).chr(0x80 | (($c >> 6) & 0x3F)).chr(0x80 | ($c & 0x3F));
}

// 单个码点的显示宽度（用户单位）
protected function _cpAdvance($p, $upem, $c)
{
 $g = $p->glyphIndex($c);
 list($a, $l) = $p->advance($g);
 return $a / $upem * $this->FontSize;
}

// 判断某码点是否允许在其后断行（CJK / 全角 / 谚文 / emoji 等宽字符）
protected function _isBreakable($c)
{
 if($c < 0x2E80) return false;
 if($c >= 0x2E80 && $c <= 0x9FFF) return true;   // CJK 统一表意文字
 if($c >= 0x3400 && $c <= 0x4DBF) return true;   // 扩展 A
 if($c >= 0x20000 && $c <= 0x2FA1F) return true;  // 扩展 B 及以后
 if($c >= 0x3000 && $c <= 0x30FF) return true;   // CJK 符号/标点、假名
 if($c >= 0xFF00 && $c <= 0xFFEF) return true;   // 全角字符
 if($c >= 0xAC00 && $c <= 0xD7AF) return true;   // 谚文音节
 if($c >= 0x1F000) return true;                  // emoji 等
 return false;
}

// 覆盖 _putpages：页面序列化之前完成字体子集化与文本占位符回填。
// 此钩子由 _enddoc 调用（晚于 Close() 写入的最后一页页脚、早于
// _putresources/_putttf 的字体嵌入），保证页脚文字也能正确回填为内嵌字形。
protected function _putpages()
{
 if(!$this->_ttfFinalized)
 {
  $this->_subsetTTFonts();
  $this->_backfillTTFragments();
 }
 parent::_putpages();
}

// 子集化所有 TTF 字体（幂等，可重复调用）
protected function _subsetTTFonts()
{
 foreach($this->fonts as $k => &$font)
 {
  if($font['type'] !== 'TTF')
   continue;
  $this->_subsetTTFont($font);
 }
 unset($font);
}

// 子集化单个 TTF 字体：生成子集数据、码点→新字形映射与各项度量
protected function _subsetTTFont(&$font)
{
 if(isset($font['subData']))
  return;
  $p = $font['parser'];
  $used = array_keys($font['used']);
  if(!in_array(0x20, $used))
   $used[] = 0x20;   // 确保空格字形存在
  sort($used, SORT_NUMERIC);
  $ss = $p->subset($used);
  $map = $ss['map'];   // origGid => newGid
  $gmap = array();
  foreach($used as $cp)
  {
   $og = $p->glyphIndex($cp);
   $gmap[$cp] = isset($map[$og]) ? $map[$og] : 0;
  }
  $font['gmap'] = $gmap;
  $font['subData'] = $ss['data'];
  $font['subLen'] = strlen($ss['data']);
  $font['subType'] = $ss['type'];
  // 宽度映射（newGid -> 千单位）。只收录内容流实际引用的字形（gmap 的值），
  // 避免 CFF 整字体嵌入时把全部几万个字形的宽度都写进 /W 数组。
  // glyf：$ss['adv'] 以 newGid 为键；CFF：$ss['adv'] 以原始 gid 为键，而
  // CFF 路径 newGid 即原始 gid，两种情况都能用 gmap 的值直接索引。
  $adv = $ss['adv'];
  $wmap = array();
  foreach($gmap as $ng)
  {
   if($ng <= 0 || isset($wmap[$ng]))
    continue;
   $a = isset($adv[$ng]) ? $adv[$ng][0] : 0;
   $wmap[$ng] = round($a / $p->unitsPerEm * 1000);
  }
  $font['W'] = $this->_buildWArray($wmap);
  // FontDescriptor 度量
  $font['desc'] = array(
   'Flags' => 4,
   'FontBBox' => '['.implode(' ', $p->fontBBox).']',
   'ItalicAngle' => intval($p->italicAngle),
   'Ascent' => $p->ascent,
   'Descent' => $p->descent,
   'CapHeight' => $p->capHeight,
   'StemV' => 80,
  );
}

// 用 gmap 回填文本占位符 (XTTF#X) -> <字形id的十六进制>（幂等）
protected function _backfillTTFragments()
{
 if($this->_ttfFinalized)
  return;
 foreach($this->_ttfFragments as $id => $fr)
 {
  $fk = $fr['fontkey'];
  $gmap = isset($this->fonts[$fk]['gmap']) ? $this->fonts[$fk]['gmap'] : array();
  $hex = '';
  foreach($fr['cp'] as $c)
   $hex .= sprintf('%04X', isset($gmap[$c]) ? $gmap[$c] : 0);
  $find = '(XTTF'.$id.'X)';
  $repl = '<'.$hex.'>';
  foreach($this->pages as $pi => &$pg)
   $pg = str_replace($find, $repl, $pg);
  unset($pg);
 }
 $this->_ttfFinalized = true;
}

// 由 newGid => 宽度(千单位) 映射生成 /W 数组片段
//  - 紧凑连续（glyf 子集，gid 为 0..n-1）→ "0 [w0 w1 ...]"（最省）
//  - 稀疏（CFF 整字体，gid 为大数值）→ 按连续段分组： "c [w ...] c [w ...]"
protected function _buildWArray($wmap)
{
 if(empty($wmap))
  return '';
 $gids = array_keys($wmap);
 sort($gids, SORT_NUMERIC);
 $n = count($gids);
 $maxg = $gids[$n - 1];
 // 紧凑连续且规模不大时用单段数组
 if($gids[0] === 0 && $n == $maxg + 1 && $maxg < 4000)
 {
  $arr = array();
  for($g = 0; $g <= $maxg; $g++)
   $arr[] = $wmap[$g];
  return '0 ['.implode(' ', $arr).']';
 }
 // 稀疏：按连续 gid 段分组
 $out = '';
 $i = 0;
 while($i < $n)
 {
  $start = $gids[$i];
  $run = array($wmap[$start]);
  $j = $i + 1;
  while($j < $n && $gids[$j] == $gids[$j - 1] + 1)
  {
   $run[] = $wmap[$gids[$j]];
   $j++;
  }
  $out .= $start.' ['.implode(' ', $run).'] ';
  $i = $j;
 }
 return trim($out);
}

// 输出 TTF/OTF 内嵌字体（Type0 + CIDFont + FontDescriptor + FontFile + ToUnicode）
protected function _putttf($font)
{
 // 兜底：正常流程中 _putpages 钩子已完成子集化；此处防御性保证数据就绪
 if(!isset($font['subData']))
  $this->_subsetTTFont($font);
 $name = 'AAAAAA+'.$font['name'];
 $this->_newobj();
 $type0obj = $this->n;
 $tuObj = $type0obj + 4;   // 依次为 Type0、CIDFont、FontDescriptor、FontFile、ToUnicode
 // 1) Type0 复合字体（引用 DescendantFonts 与 ToUnicode）
 $this->_out('<</Type /Font /Subtype /Type0 /BaseFont /'.$name
  .' /Encoding /Identity-H /DescendantFonts ['.($type0obj+1).' 0 R]'
  .' /ToUnicode '.$tuObj.' 0 R>>');
 $this->_out('endobj');
 // 2) CIDFont descendant 字体
 $this->_newobj();
 $subtype = ($font['subType']=='cff') ? '/Subtype /CIDFontType0' : '/Subtype /CIDFontType2';
 $this->_out('<</Type /Font '.$subtype.' /BaseFont /'.$name);
 $this->_out('/CIDSystemInfo <</Registry (Adobe) /Ordering (Identity) /Supplement 0>>');
 $this->_out('/FontDescriptor '.($type0obj+2).' 0 R');
 $this->_out('/DW 1000');
 $this->_out('/W ['.$font['W'].']');
 $this->_out('>>');
 $this->_out('endobj');
 // 3) 字体描述符（FontDescriptor）
 $this->_newobj();
 $s = '<</Type /FontDescriptor /FontName /'.$name;
 foreach($font['desc'] as $k => $v)
  $s .= ' /'.$k.' '.$v;
 $s .= ($font['subType']=='cff') ? ' /FontFile3 '.($type0obj+3).' 0 R' : ' /FontFile2 '.($type0obj+3).' 0 R';
 $this->_out($s.'>>');
 $this->_out('endobj');
 // 4) 字体文件流（子集）。注意：声明 /Filter /FlateDecode 时必须真正用
 //    gzcompress 压缩；否则阅读器按 deflate 解析原始 TrueType 字节会因压缩
 //    方法字节非 8 而报 “Unknown compression method”，导致字体加载失败、文字
 //    整页空白。故按 $this->compress 开关决定是否压缩。
 $this->_newobj();
 $data = $font['subData'];
 if($this->compress)
 {
  $fdata = gzcompress($data);
  $filter = '/Filter /FlateDecode ';
 }
 else
 {
  $fdata = $data;
  $filter = '';
 }
 $subtype = ($font['subType']=='cff') ? '/Subtype /CIDFontType0C ' : '';
 $this->_out('<</Length '.strlen($fdata).' '.$subtype.$filter.'>>');
 $this->_putstream($fdata);
 $this->_out('endobj');
 // 5) ToUnicode CMap（便于文本复制/检索），由 _putstreamobject 创建并闭合对象
 $this->_putstreamobject($this->_tounicodeTTF($font));
}

// 生成 ToUnicode CMap（newGid -> Unicode 码点）
protected function _tounicodeTTF($font)
{
 $gmap = $font['gmap'];
 $ng2cp = array();
 foreach($gmap as $cp => $ng)
 {
  if($ng > 0)
   $ng2cp[$ng] = $cp;
 }
 ksort($ng2cp);
 $s = "/CIDInit /ProcSet findresource begin\n";
 $s .= "12 dict begin\n";
 $s .= "begincmap\n";
 $s .= "/CIDSystemInfo\n<</Registry (Adobe)\n/Ordering (Identity)\n/Supplement 0>> def\n";
 $s .= "/CMapName /Identity-UCS def\n/CMapType 2 def\n";
 $s .= "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
 if(count($ng2cp))
 {
  $s .= "beginbfchar\n";
  foreach($ng2cp as $ng => $cp)
   $s .= sprintf("<%04X> <%04X>\n", $ng, $cp);
  $s .= "endbfchar\n";
 }
 $s .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
 return $s;
}
}
?>