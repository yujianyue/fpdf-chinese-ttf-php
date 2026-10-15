<?php
/*******************************************************************************
* FPDF                                                                         *
*                                                                              *
* Version: 1.84                                                                *
* Date:    2021-08-28                                                          *
* Author:  Olivier PLATHEY                                                     *
*******************************************************************************/

define('FPDF_VERSION','1.84');

// ----------------------------------------------------------------------------
// 改进作者: yujianyue <15058593138@qq.com>
// 兼容性修复版（PHP 5.5+ 全版本兼容，已验证 PHP 5.5 / 7.x / 8.0 / 8.1 / 8.2 / 8.3）
// 修复内容：
//  1) 自实现 Latin-1→UTF-8（_utf8），摆脱 mbstring 依赖并规避 utf8_encode 在
//     PHP 8.2+ 弃用/ PHP 9 移除问题；
//  2) 文件名/路径 null 字节注入防护（Image / Output / AddFont / _loadfont）；
//  3) 未设置字体时 GetStringWidth/MultiCell/Write 抛出友好错误，避免 PHP 7.4+
//     “对 null 取数组下标”致命错误；
//  4) _httpencode 守卫 $_SERVER['HTTP_USER_AGENT']（CLI/无该键不再报未定义索引）；
//  5) _dochecks 对 mbstring.func_overload 做 (int) 强转（PHP 8.0 已移除该 ini）；
//  6) _loadfont 增加文件存在性检查与更清晰报错；
//  7) _escape 移除 PDF 字面量字符串中的 null 字节（防止 PDF 被截断损坏）；
//  8) _putfonts 以 !empty($font['subsetted']) 代替直接下标访问（消除未定义键警告）。
// ----------------------------------------------------------------------------

class FPDF
{
protected $page;  // 当前页码
protected $n;  // PDF 对象编号计数器
protected $offsets;  // 各对象在缓冲区中的偏移量
protected $buffer;  // PDF 文档内容缓冲区
protected $pages;  // 各页内容（字符串数组）
protected $state;  // 文档状态：0 未开始 / 1 已封页 / 2 页内 / 3 已关闭
protected $compress;  // 是否压缩页面内容
protected $k;  // 缩放系数（用户单位 → 点，1pt=1/72in）
protected $DefOrientation;  // 默认页面方向（P/L）
protected $CurOrientation;  // 当前页面方向
protected $StdPageSizes;  // 标准页面尺寸表（点）
protected $DefPageSize;  // 默认页面尺寸（点）
protected $CurPageSize;  // 当前页面尺寸（点）
protected $CurRotation;  // 当前页面旋转角度
protected $PageInfo;  // 页面附加信息（尺寸 / 旋转）
protected $wPt, $hPt;  // 页面宽/高（以点为单位）
protected $w, $h;  // 页面宽/高（以用户单位）
protected $lMargin;  // 左边距
protected $tMargin;  // 上边距
protected $rMargin;  // 右边距
protected $bMargin;  // 下边距（触发自动分页）
protected $cMargin;  // 单元格内部间距
protected $x, $y;  // 当前 x / y 坐标（用户单位）
protected $lasth;  // 最近一次输出的单元格高度
protected $LineWidth;  // 当前线条宽度
protected $fontpath;  // 字体文件所在目录
protected $CoreFonts;  // 核心字体名称清单
protected $fonts;  // 已加载字体信息
protected $FontFiles;  // 嵌入字体文件信息
protected $encodings;  // 字符编码差异表（/Differences）
protected $cmaps;  // ToUnicode 字符映射表缓存
protected $FontFamily;  // 当前字体族名称
protected $FontStyle;  // 当前字体样式（B/I/U 组合）
protected $underline;  // 是否开启下划线
protected $CurrentFont;  // 当前生效的字体信息
protected $FontSizePt;  // 当前字号（磅）
protected $FontSize;  // 当前字号（用户单位）
protected $DrawColor;  // 描边颜色
protected $FillColor;  // 填充颜色
protected $TextColor;  // 文本颜色
protected $ColorFlag;  // 填充色与文本色是否不同
protected $WithAlpha;  // 是否使用 alpha 透明通道
protected $ws;  // 字符间距（字距）
protected $images;  // 已使用图片信息
protected $PageLinks;  // 各页上的链接热区
protected $links;  // 文档内部链接表
protected $AutoPageBreak;  // 是否启用自动分页
protected $PageBreakTrigger;  // 触发自动分页的 y 坐标阈值
protected $InHeader;  // 是否正在输出页眉
protected $InFooter;  // 是否正在输出页脚
protected $AliasNbPages;  // 总页数别名字符串
protected $ZoomMode;  // 默认缩放模式
protected $LayoutMode;  // 默认版面模式
protected $metadata;  // 文档元信息（标题/作者等）
protected $PDFVersion;  // 生成的 PDF 版本号

/*******************************************************************************
*                               Public methods                                 *
*******************************************************************************/

function __construct($orientation='P', $unit='mm', $size='diy')
{
	//若干前置检查
	$this->_dochecks();
	//初始化各项属性
	$this->state = 0;
	$this->page = 0;
	$this->n = 2;
	$this->buffer = '';
	$this->pages = array();
	$this->PageInfo = array();
	$this->fonts = array();
	$this->FontFiles = array();
	$this->encodings = array();
	$this->cmaps = array();
	$this->images = array();
	$this->links = array();
	$this->InHeader = false;
	$this->InFooter = false;
	$this->lasth = 0;
	$this->FontFamily = '';
	$this->FontStyle = '';
	$this->FontSizePt = 12;
	$this->underline = false;
	$this->DrawColor = '0 G';
	$this->FillColor = '0 g';
	$this->TextColor = '0 g';
	$this->ColorFlag = false;
	$this->WithAlpha = false;
	$this->ws = 0;
	//字体文件目录
	if(defined('FPDF_FONTPATH'))
	{
		$this->fontpath = FPDF_FONTPATH;
		if(substr($this->fontpath,-1)!='/' && substr($this->fontpath,-1)!='\\')
			$this->fontpath .= '/';
	}
	elseif(is_dir(dirname(__FILE__).'/font'))
		$this->fontpath = dirname(__FILE__).'/font/';
	else
		$this->fontpath = '';
	//核心字体（内置字体族）
	$this->CoreFonts = array('courier', 'helvetica', 'times', 'symbol', 'zapfdingbats');
	//缩放因子（用户单位 → 点）
	if($unit=='pt')
		$this->k = 1;
	elseif($unit=='mm')
		$this->k = 72/25.4;
	elseif($unit=='cm')
		$this->k = 72/2.54;
	elseif($unit=='in')
		$this->k = 72;
	else
		$this->Error('Incorrect unit: '.$unit);
	//页面尺寸定义
	$this->StdPageSizes = array('a3'=>array(841.89,1190.55), 'a4'=>array(595.28,841.89), 'a5'=>array(420.94,595.28), 'a4a'=>array(595.28,841.89), 'letter'=>array(612,792), 'legal'=>array(612,1008), 'diy'=>array(595.28,370.88));
	$size = $this->_getpagesize($size);
	$this->DefPageSize = $size;
	$this->CurPageSize = $size;
	//页面方向
	$orientation = strtolower($orientation);
	if($orientation=='p' || $orientation=='portrait')
	{
		$this->DefOrientation = 'P';
		$this->w = $size[0];
		$this->h = $size[1];
	}
	elseif($orientation=='l' || $orientation=='landscape')
	{
		$this->DefOrientation = 'L';
		$this->w = $size[1];
		$this->h = $size[0];
	}
	else
		$this->Error('Incorrect orientation: '.$orientation);
	$this->CurOrientation = $this->DefOrientation;
	$this->wPt = $this->w*$this->k;
	$this->hPt = $this->h*$this->k;
	//页面旋转角度
	$this->CurRotation = 0;
	//页面边距（默认 1 厘米）
	$margin = 28.35/$this->k;
	$this->SetMargins($margin,$margin);
	//单元格内部间距（默认 1 毫米）
	$this->cMargin = $margin/10;
	//默认线条宽度（0.2 毫米）
	$this->LineWidth = .567/$this->k;
	//自动分页
	$this->SetAutoPageBreak(true,2*$margin);
	//默认显示模式
	$this->SetDisplayMode('default');
	//启用页面压缩
	$this->SetCompression(true);
	//设置默认 PDF 版本号
	$this->PDFVersion = '1.3';
}

function SetMargins($left, $top, $right=null)
{
	//设置左、上、右边距
	$this->lMargin = $left;
	$this->tMargin = $top;
	if($right===null)
		$right = $left;
	$this->rMargin = $right;
}

function SetLeftMargin($margin)
{
	//设置左边距
	$this->lMargin = $margin;
	if($this->page>0 && $this->x<$margin)
		$this->x = $margin;
}

function SetTopMargin($margin)
{
	//设置上边距
	$this->tMargin = $margin;
}

function SetRightMargin($margin)
{
	//设置右边距
	$this->rMargin = $margin;
}

function SetAutoPageBreak($auto, $margin=0)
{
	//设置自动分页模式及触发边距
	$this->AutoPageBreak = $auto;
	$this->bMargin = $margin;
	$this->PageBreakTrigger = $this->h-$margin;
}

function SetDisplayMode($zoom, $layout='default')
{
	//设置阅读器中的显示模式
	if($zoom=='fullpage' || $zoom=='fullwidth' || $zoom=='real' || $zoom=='default' || !is_string($zoom))
		$this->ZoomMode = $zoom;
	else
		$this->Error('Incorrect zoom display mode: '.$zoom);
	if($layout=='single' || $layout=='continuous' || $layout=='two' || $layout=='default')
		$this->LayoutMode = $layout;
	else
		$this->Error('Incorrect layout display mode: '.$layout);
}

function SetCompression($compress)
{
	//设置页面是否压缩
	if(function_exists('gzcompress'))
		$this->compress = $compress;
	else
		$this->compress = false;
}

function SetTitle($title, $isUTF8=false)
{
	//文档标题
	$this->metadata['Title'] = $isUTF8 ? $title : $this->_utf8($title);
}

function SetAuthor($author, $isUTF8=false)
{
	//文档作者
	$this->metadata['Author'] = $isUTF8 ? $author : $this->_utf8($author);
}

function SetSubject($subject, $isUTF8=false)
{
	//文档主题
	$this->metadata['Subject'] = $isUTF8 ? $subject : $this->_utf8($subject);
}

function SetKeywords($keywords, $isUTF8=false)
{
	//文档关键词
	$this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_utf8($keywords);
}

function SetCreator($creator, $isUTF8=false)
{
	//文档创建者
	$this->metadata['Creator'] = $isUTF8 ? $creator : $this->_utf8($creator);
}

function AliasNbPages($alias='{nb}')
{
	//定义总页数别名（输出时替换）
	$this->AliasNbPages = $alias;
}

function Error($msg)
{
	//致命错误
	throw new Exception('FPDF error: '.$msg);
}

function Close()
{
	//结束整个文档
	if($this->state==3)
		return;
	if($this->page==0)
		$this->AddPage();
	//页脚
	$this->InFooter = true;
	$this->Footer();
	$this->InFooter = false;
	//关闭当前页
	$this->_endpage();
	//关闭文档
	$this->_enddoc();
}

function AddPage($orientation='', $size='', $rotation=0)
{
	//开始新的一页
	if($this->state==3)
		$this->Error('The document is closed');
	$family = $this->FontFamily;
	$style = $this->FontStyle.($this->underline ? 'U' : '');
	$fontsize = $this->FontSizePt;
	$lw = $this->LineWidth;
	$dc = $this->DrawColor;
	$fc = $this->FillColor;
	$tc = $this->TextColor;
	$cf = $this->ColorFlag;
	if($this->page>0)
	{
		//页脚
		$this->InFooter = true;
		$this->Footer();
		$this->InFooter = false;
		//关闭当前页
		$this->_endpage();
	}
	//开始新页
	$this->_beginpage($orientation,$size,$rotation);
	//将线帽样式设为方头
	$this->_out('2 J');
	//设置线条宽度
	$this->LineWidth = $lw;
	$this->_out(sprintf('%.2F w',$lw*$this->k));
	//设置字体
	if($family)
		$this->SetFont($family,$style,$fontsize);
	//恢复/设置颜色
	$this->DrawColor = $dc;
	if($dc!='0 G')
		$this->_out($dc);
	$this->FillColor = $fc;
	if($fc!='0 g')
		$this->_out($fc);
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
	//页眉
	$this->InHeader = true;
	$this->Header();
	$this->InHeader = false;
	//恢复线条宽度
	if($this->LineWidth!=$lw)
	{
		$this->LineWidth = $lw;
		$this->_out(sprintf('%.2F w',$lw*$this->k));
	}
	//恢复字体
	if($family)
		$this->SetFont($family,$style,$fontsize);
	//恢复颜色
	if($this->DrawColor!=$dc)
	{
		$this->DrawColor = $dc;
		$this->_out($dc);
	}
	if($this->FillColor!=$fc)
	{
		$this->FillColor = $fc;
		$this->_out($fc);
	}
	$this->TextColor = $tc;
	$this->ColorFlag = $cf;
}

function Header()
{
	//可由继承类自行重写实现
}

function Footer()
{
	//可由继承类自行重写实现
}

function PageNo()
{
	//获取当前页码
	return $this->page;
}

function SetDrawColor($r, $g=null, $b=null)
{
	//设置所有描边（画线）操作的颜色
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->DrawColor = sprintf('%.3F G',$r/255);
	else
		$this->DrawColor = sprintf('%.3F %.3F %.3F RG',$r/255,$g/255,$b/255);
	if($this->page>0)
		$this->_out($this->DrawColor);
}

function SetFillColor($r, $g=null, $b=null)
{
	//设置所有填充操作的颜色
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->FillColor = sprintf('%.3F g',$r/255);
	else
		$this->FillColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
	if($this->page>0)
		$this->_out($this->FillColor);
}

function SetTextColor($r, $g=null, $b=null)
{
	//设置文本颜色
	if(($r==0 && $g==0 && $b==0) || $g===null)
		$this->TextColor = sprintf('%.3F g',$r/255);
	else
		$this->TextColor = sprintf('%.3F %.3F %.3F rg',$r/255,$g/255,$b/255);
	$this->ColorFlag = ($this->FillColor!=$this->TextColor);
}

function GetStringWidth($s)
{
	//计算当前字体下字符串的显示宽度
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
	$s = (string)$s;
	$cw = &$this->CurrentFont['cw'];
	$w = 0;
	$l = strlen($s);
	for($i=0;$i<$l;$i++)
		$w += $cw[$s[$i]];
	return $w*$this->FontSize/1000;
}

function SetLineWidth($width)
{
	//设置线条宽度
	$this->LineWidth = $width;
	if($this->page>0)
		$this->_out(sprintf('%.2F w',$width*$this->k));
}

function Line($x1, $y1, $x2, $y2)
{
	//绘制一条直线
	$this->_out(sprintf('%.2F %.2F m %.2F %.2F l S',$x1*$this->k,($this->h-$y1)*$this->k,$x2*$this->k,($this->h-$y2)*$this->k));
}

function Rect($x, $y, $w, $h, $style='')
{
	//绘制一个矩形
	if($style=='F')
		$op = 'f';
	elseif($style=='FD' || $style=='DF')
		$op = 'B';
	else
		$op = 'S';
	$this->_out(sprintf('%.2F %.2F %.2F %.2F re %s',$x*$this->k,($this->h-$y)*$this->k,$w*$this->k,-$h*$this->k,$op));
}

function AddFont($family, $style='', $file='')
{
	//添加 TrueType / OpenType 或 Type1 字体
	$family = strtolower($family);
	if($file=='')
		$file = str_replace(' ','',$family).strtolower($style).'.php';
	$style = strtoupper($style);
	if($style=='IB')
		$style = 'BI';
	$fontkey = $family.$style;
	if(isset($this->fonts[$fontkey]))
		return;
	$info = $this->_loadfont($file);
	$info['i'] = count($this->fonts)+1;
	if(!empty($info['file']))
	{
		//嵌入字体
		if($info['type']=='TrueType')
			$this->FontFiles[$info['file']] = array('length1'=>$info['originalsize']);
		else
			$this->FontFiles[$info['file']] = array('length1'=>$info['size1'], 'length2'=>$info['size2']);
	}
	$this->fonts[$fontkey] = $info;
}

function SetFont($family, $style='', $size=0)
{
	//选择字体（字号以磅 pt 为单位）
	if($family=='')
		$family = $this->FontFamily;
	else
		$family = strtolower($family);
	$style = strtoupper($style);
	if(strpos($style,'U')!==false)
	{
		$this->underline = true;
		$style = str_replace('U','',$style);
	}
	else
		$this->underline = false;
	if($style=='IB')
		$style = 'BI';
	if($size==0)
		$size = $this->FontSizePt;
	//检查该字体是否已被选中
	if($this->FontFamily==$family && $this->FontStyle==$style && $this->FontSizePt==$size)
		return;
	//检查该字体是否已被加载
	$fontkey = $family.$style;
	if(!isset($this->fonts[$fontkey]))
	{
		//检查是否为某个核心字体
		if($family=='arial')
			$family = 'helvetica';
		if(in_array($family,$this->CoreFonts))
		{
			if($family=='symbol' || $family=='zapfdingbats')
				$style = '';
			$fontkey = $family.$style;
			if(!isset($this->fonts[$fontkey]))
				$this->AddFont($family,$style);
		}
		else
			$this->Error('Undefined font: '.$family.' '.$style);
	}
	//选中该字体
	$this->FontFamily = $family;
	$this->FontStyle = $style;
	$this->FontSizePt = $size;
	$this->FontSize = $size/$this->k;
	$this->CurrentFont = &$this->fonts[$fontkey];
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function SetFontSize($size)
{
	//设置字号（磅 pt）
	if($this->FontSizePt==$size)
		return;
	$this->FontSizePt = $size;
	$this->FontSize = $size/$this->k;
	if($this->page>0)
		$this->_out(sprintf('BT /F%d %.2F Tf ET',$this->CurrentFont['i'],$this->FontSizePt));
}

function AddLink()
{
	//新建一个文档内部链接
	$n = count($this->links)+1;
	$this->links[$n] = array(0, 0);
	return $n;
}

function SetLink($link, $y=0, $page=-1)
{
	//设置内部链接的目标位置
	if($y==-1)
		$y = $this->y;
	if($page==-1)
		$page = $this->page;
	$this->links[$link] = array($page, $y);
}

function Link($x, $y, $w, $h, $link)
{
	//在页面上放置一个链接热区
	$this->PageLinks[$this->page][] = array($x*$this->k, $this->hPt-$y*$this->k, $w*$this->k, $h*$this->k, $link);
}

function Text($x, $y, $txt)
{
	//输出一段文本
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
	$s = sprintf('BT %.2F %.2F Td (%s) Tj ET',$x*$this->k,($this->h-$y)*$this->k,$this->_escape($txt));
	if($this->underline && $txt!='')
		$s .= ' '.$this->_dounderline($x,$y,$txt);
	if($this->ColorFlag)
		$s = 'q '.$this->TextColor.' '.$s.' Q';
	$this->_out($s);
}

function AcceptPageBreak()
{
	//是否允许自动分页
	return $this->AutoPageBreak;
}

function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link='')
{
	//输出一个单元格
	$k = $this->k;
	if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
	{
		//自动分页
		$x = $this->x;
		$ws = $this->ws;
		if($ws>0)
		{
			$this->ws = 0;
			$this->_out('0 Tw');
		}
		$this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
		$this->x = $x;
		if($ws>0)
		{
			$this->ws = $ws;
			$this->_out(sprintf('%.3F Tw',$ws*$k));
		}
	}
	if($w==0)
		$w = $this->w-$this->rMargin-$this->x;
	$s = '';
	if($fill || $border==1)
	{
		if($fill)
			$op = ($border==1) ? 'B' : 'f';
		else
			$op = 'S';
		$s = sprintf('%.2F %.2F %.2F %.2F re %s ',$this->x*$k,($this->h-$this->y)*$k,$w*$k,-$h*$k,$op);
	}
	if(is_string($border))
	{
		$x = $this->x;
		$y = $this->y;
		if(strpos($border,'L')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,$x*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'T')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-$y)*$k);
		if(strpos($border,'R')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',($x+$w)*$k,($this->h-$y)*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
		if(strpos($border,'B')!==false)
			$s .= sprintf('%.2F %.2F m %.2F %.2F l S ',$x*$k,($this->h-($y+$h))*$k,($x+$w)*$k,($this->h-($y+$h))*$k);
	}
	if($txt!=='')
	{
		if(!isset($this->CurrentFont))
			$this->Error('No font has been set');
		if($align=='R')
			$dx = $w-$this->cMargin-$this->GetStringWidth($txt);
		elseif($align=='C')
			$dx = ($w-$this->GetStringWidth($txt))/2;
		else
			$dx = $this->cMargin;
		if($this->ColorFlag)
			$s .= 'q '.$this->TextColor.' ';
		$s .= sprintf('BT %.2F %.2F Td (%s) Tj ET',($this->x+$dx)*$k,($this->h-($this->y+.5*$h+.3*$this->FontSize))*$k,$this->_escape($txt));
		if($this->underline)
			$s .= ' '.$this->_dounderline($this->x+$dx,$this->y+.5*$h+.3*$this->FontSize,$txt);
		if($this->ColorFlag)
			$s .= ' Q';
		if($link)
			$this->Link($this->x+$dx,$this->y+.5*$h-.5*$this->FontSize,$this->GetStringWidth($txt),$this->FontSize,$link);
	}
	if($s)
		$this->_out($s);
	$this->lasth = $h;
	if($ln>0)
	{
		//换行（移动到下一行起始）
		$this->y += $h;
		if($ln==1)
			$this->x = $this->lMargin;
	}
	else
		$this->x += $w;
}

function MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false)
{
	//输出文本（支持自动换行与显式换行）
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
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
			$b = (strpos($border,'T')!==false) ? $b2.'T' : $b2;
		}
	}
	$sep = -1;
	$i = 0;
	$j = 0;
	$l = 0;
	$ns = 0;
	$nl = 1;
	while($i<$nb)
	{
		//获取下一个字符
		$c = $s[$i];
		if($c=="\n")
		{
			//显式换行（遇到 \n）
			if($this->ws>0)
			{
				$this->ws = 0;
				$this->_out('0 Tw');
			}
			$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			$i++;
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
			continue;
		}
		if($c==' ')
		{
			$sep = $i;
			$ls = $l;
			$ns++;
		}
		$l += $cw[$c];
		if($l>$wmax)
		{
			//自动换行（超出宽度时）
			if($sep==-1)
			{
				if($i==$j)
					$i++;
				if($this->ws>0)
				{
					$this->ws = 0;
					$this->_out('0 Tw');
				}
				$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
			}
			else
			{
				if($align=='J')
				{
					$this->ws = ($ns>1) ? ($wmax-$ls)/1000*$this->FontSize/($ns-1) : 0;
					$this->_out(sprintf('%.3F Tw',$this->ws*$this->k));
				}
				$this->Cell($w,$h,substr($s,$j,$sep-$j),$b,2,$align,$fill);
				$i = $sep+1;
			}
			$sep = -1;
			$j = $i;
			$l = 0;
			$ns = 0;
			$nl++;
			if($border && $nl==2)
				$b = $b2;
		}
		else
			$i++;
	}
	//输出最后一段剩余文本
	if($this->ws>0)
	{
		$this->ws = 0;
		$this->_out('0 Tw');
	}
	if($border && strpos($border,'B')!==false)
		$b .= 'B';
	$this->Cell($w,$h,substr($s,$j,$i-$j),$b,2,$align,$fill);
	$this->x = $this->lMargin;
}

function Write($h, $txt, $link='')
{
	//以流式（自动换行）方式输出文本
	if(!isset($this->CurrentFont))
		$this->Error('No font has been set');
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
		if($c=="\n")
		{
			//显式换行（遇到 \n）
			$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
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
		if($c==' ')
			$sep = $i;
		$l += $cw[$c];
		if($l>$wmax)
		{
			//自动换行（超出宽度时）
			if($sep==-1)
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
					$i++;
				$this->Cell($w,$h,substr($s,$j,$i-$j),0,2,'',false,$link);
			}
			else
			{
				$this->Cell($w,$h,substr($s,$j,$sep-$j),0,2,'',false,$link);
				$i = $sep+1;
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
			$i++;
	}
	//输出最后一段剩余文本
	if($i!=$j)
		$this->Cell($l/1000*$this->FontSize,$h,substr($s,$j),0,0,'',false,$link);
}

function Ln($h=null)
{
	//换行；默认高度为最近一次单元格高度
	$this->x = $this->lMargin;
	if($h===null)
		$this->y += $this->lasth;
	else
		$this->y += $h;
}

function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
{
	//在页面上放置一张图片
	if($file=='')
		$this->Error('Image file name is empty');
	if(strpos($file,"\0")!==false)
		$this->Error('Image file name contains a null byte: '.$file);
	if(!isset($this->images[$file]))
	{
		//首次使用此图片时读取其信息
		if($type=='')
		{
			$pos = strrpos($file,'.');
			if(!$pos)
				$this->Error('Image file has no extension and no type was specified: '.$file);
			$type = substr($file,$pos+1);
		}
		$type = strtolower($type);
		if($type=='jpeg')
			$type = 'jpg';
		$mtd = '_parse'.$type;
		if(!method_exists($this,$mtd))
			$this->Error('Unsupported image type: '.$type);
		$info = $this->$mtd($file);
		$info['i'] = count($this->images)+1;
		$this->images[$file] = $info;
	}
	else
		$info = $this->images[$file];

	//必要时自动计算图片宽高
	if($w==0 && $h==0)
	{
		//按 96 DPI 估算图片显示尺寸
		$w = -96;
		$h = -96;
	}
	if($w<0)
		$w = -$info['w']*72/$w/$this->k;
	if($h<0)
		$h = -$info['h']*72/$h/$this->k;
	if($w==0)
		$w = $h*$info['w']/$info['h'];
	if($h==0)
		$h = $w*$info['h']/$info['w'];

	//流式放置模式（自动换行）
	if($y===null)
	{
		if($this->y+$h>$this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak())
		{
			//自动分页
			$x2 = $this->x;
			$this->AddPage($this->CurOrientation,$this->CurPageSize,$this->CurRotation);
			$this->x = $x2;
		}
		$y = $this->y;
		$this->y += $h;
	}

	if($x===null)
		$x = $this->x;
	$this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',$w*$this->k,$h*$this->k,$x*$this->k,($this->h-($y+$h))*$this->k,$info['i']));
	if($link)
		$this->Link($x,$y,$w,$h,$link);
}

function GetPageWidth()
{
	//获取当前页宽度
	return $this->w;
}

function GetPageHeight()
{
	//获取当前页高度
	return $this->h;
}

function GetX()
{
	//获取当前 x 坐标
	return $this->x;
}

function SetX($x)
{
	//设置当前 x 坐标
	if($x>=0)
		$this->x = $x;
	else
		$this->x = $this->w+$x;
}

function GetY()
{
	//获取当前 y 坐标
	return $this->y;
}

function SetY($y, $resetX=true)
{
	//设置 y 坐标（并可选择性重置 x）
	if($y>=0)
		$this->y = $y;
	else
		$this->y = $this->h+$y;
	if($resetX)
		$this->x = $this->lMargin;
}

function SetXY($x, $y)
{
	//同时设置 x 与 y 坐标
	$this->SetX($x);
	$this->SetY($y,false);
}

function Output($dest='', $name='', $isUTF8=false)
{
	//将 PDF 输出到指定目标
	$this->Close();
	if(strpos($name,"\0")!==false)
		$this->Error('Output file name contains a null byte: '.$name);
	if(strlen($name)==1 && strlen($dest)!=1)
	{
		//修正参数顺序（兼容旧调用方式）
		$tmp = $dest;
		$dest = $name;
		$name = $tmp;
	}
	if($dest=='')
		$dest = 'I';
	if($name=='')
		$name = 'doc.pdf';
	switch(strtoupper($dest))
	{
		case 'I':
			//发送到标准输出
			$this->_checkoutput();
			if(PHP_SAPI!='cli')
			{
				//向浏览器发送（内联显示）
				header('Content-Type: application/pdf');
				header('Content-Disposition: inline; '.$this->_httpencode('filename',$name,$isUTF8));
				header('Cache-Control: private, max-age=0, must-revalidate');
				header('Pragma: public');
			}
			echo $this->buffer;
			break;
		case 'D':
			//作为附件下载
			$this->_checkoutput();
			header('Content-Type: application/x-download');
			header('Content-Disposition: attachment; '.$this->_httpencode('filename',$name,$isUTF8));
			header('Cache-Control: private, max-age=0, must-revalidate');
			header('Pragma: public');
			echo $this->buffer;
			break;
		case 'F':
			//保存到本地文件
			if(!file_put_contents($name,$this->buffer))
				$this->Error('Unable to create output file: '.$name);
			break;
		case 'S':
			//以字符串形式返回 PDF 内容
			return $this->buffer;
		default:
			$this->Error('Incorrect output destination: '.$dest);
	}
	return '';
}

/*******************************************************************************
*                              Protected methods                               *
*******************************************************************************/

protected function _dochecks()
{
	//检查 mbstring 函数重载
	//（mbstring.func_overload 已在 PHP 8.0 中移除，ini_get() 此时返回
	// false，经 (int) 强转后为 0，因此在所有版本下均安全。）
	if(((int)ini_get('mbstring.func_overload')) & 2)
		$this->Error('mbstring overloading must be disabled');
}

protected function _checkoutput()
{
	if(PHP_SAPI!='cli')
	{
		if(headers_sent($file,$line))
			$this->Error("Some data has already been output, can't send PDF file (output started at $file:$line)");
	}
	if(ob_get_length())
	{
		//输出缓冲区不为空
		if(preg_match('/^(\xEF\xBB\xBF)?\s*$/',ob_get_contents()))
		{
			//其中仅含 UTF-8 BOM 与/或空白，清理之
			ob_clean();
		}
		else
			$this->Error("Some data has already been output, can't send PDF file");
	}
}

protected function _getpagesize($size)
{
	if(is_string($size))
	{
		$size = strtolower($size);
		if(!isset($this->StdPageSizes[$size]))
			$this->Error('Unknown page size: '.$size);
		$a = $this->StdPageSizes[$size];
		return array($a[0]/$this->k, $a[1]/$this->k);
	}
	else
	{
		if($size[0]>$size[1])
			return array($size[1], $size[0]);
		else
			return $size;
	}
}

protected function _beginpage($orientation, $size, $rotation)
{
	$this->page++;
	$this->pages[$this->page] = '';
	$this->PageLinks[$this->page] = array();
	$this->state = 2;
	$this->x = $this->lMargin;
	$this->y = $this->tMargin;
	$this->FontFamily = '';
	//检查页面尺寸与方向
	if($orientation=='')
		$orientation = $this->DefOrientation;
	else
		$orientation = strtoupper($orientation[0]);
	if($size=='')
		$size = $this->DefPageSize;
	else
		$size = $this->_getpagesize($size);
	if($orientation!=$this->CurOrientation || $size[0]!=$this->CurPageSize[0] || $size[1]!=$this->CurPageSize[1])
	{
		//尺寸或方向发生变化
		if($orientation=='P')
		{
			$this->w = $size[0];
			$this->h = $size[1];
		}
		else
		{
			$this->w = $size[1];
			$this->h = $size[0];
		}
		$this->wPt = $this->w*$this->k;
		$this->hPt = $this->h*$this->k;
		$this->PageBreakTrigger = $this->h-$this->bMargin;
		$this->CurOrientation = $orientation;
		$this->CurPageSize = $size;
	}
	if($orientation!=$this->DefOrientation || $size[0]!=$this->DefPageSize[0] || $size[1]!=$this->DefPageSize[1])
		$this->PageInfo[$this->page]['size'] = array($this->wPt, $this->hPt);
	if($rotation!=0)
	{
		if($rotation%90!=0)
			$this->Error('Incorrect rotation value: '.$rotation);
		$this->CurRotation = $rotation;
		$this->PageInfo[$this->page]['rotation'] = $rotation;
	}
}

protected function _endpage()
{
	$this->state = 1;
}

protected function _loadfont($font)
{
	//从字体目录加载字体定义文件
	if(strpos($font,"\0")!==false)
		$this->Error('Incorrect font definition file name: '.$font);
	if(strpos($font,'/')!==false || strpos($font,"\\")!==false)
		$this->Error('Incorrect font definition file name: '.$font);
	$path = $this->fontpath.$font;
	if($this->fontpath!=='' && !is_file($path))
		$this->Error('Font definition file not found: '.$path);
	include($path);
	if(!isset($name))
		$this->Error('Could not include font definition file: '.$font);
	if(isset($enc))
		$enc = strtolower($enc);
	if(!isset($subsetted))
		$subsetted = false;
	return get_defined_vars();
}

protected function _isascii($s)
{
	//判断字符串是否全为 ASCII
	$nb = strlen($s);
	for($i=0;$i<$nb;$i++)
	{
		if(ord($s[$i])>127)
			return false;
	}
	return true;
}

protected function _httpencode($param, $value, $isUTF8)
{
	//对 HTTP 头字段参数进行编码
	if($this->_isascii($value))
		return $param.'="'.$value.'"';
	if(!$isUTF8)
		$value = $this->_utf8($value);
	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if(strpos($ua,'MSIE')!==false)
		return $param.'="'.rawurlencode($value).'"';
	else
		return $param."*=UTF-8''".rawurlencode($value);
}

protected function _utf8($s)
{
	//将 ISO-8859-1（Latin-1）字符串转换为 UTF-8。
	//采用自包含的字节映射实现，使本库不再依赖
	//mbstring 扩展，并在以下版本中保持行为不变：
	//PHP 8.2+ 中内置的 utf8_encode() 已被弃用（并将于
	//PHP 9 中移除）。对 Latin-1 输入，结果与 utf8_encode() 一致。
	$res = '';
	$nb = strlen($s);
	for($i=0;$i<$nb;$i++)
	{
		$c = ord($s[$i]);
		if($c < 0x80)
			$res .= $s[$i];
		else
			$res .= chr(0xC0 | ($c >> 6)).chr(0x80 | ($c & 0x3F));
	}
	return $res;
}

protected function _UTF8toUTF16($s)
{
	//将 UTF-8 转换为带 BOM 的 UTF-16BE
	$res = "\xFE\xFF";
	$nb = strlen($s);
	$i = 0;
	while($i<$nb)
	{
		$c1 = ord($s[$i++]);
		if($c1>=224)
		{
			//三字节字符（UTF-8）
			$c2 = ord($s[$i++]);
			$c3 = ord($s[$i++]);
			$res .= chr((($c1 & 0x0F)<<4) + (($c2 & 0x3C)>>2));
			$res .= chr((($c2 & 0x03)<<6) + ($c3 & 0x3F));
		}
		elseif($c1>=192)
		{
			//双字节字符（UTF-8）
			$c2 = ord($s[$i++]);
			$res .= chr(($c1 & 0x1C)>>2);
			$res .= chr((($c1 & 0x03)<<6) + ($c2 & 0x3F));
		}
		else
		{
			//单字节字符
			$res .= "\0".chr($c1);
		}
	}
	return $res;
}

protected function _escape($s)
{
	//转义 PDF 字面量字符串中的特殊字符。
	//会剔除 null 字节，否则它会提前终止
	//生成的 PDF 中的字符串，导致文档损坏。
	$s = str_replace("\0",'',$s);
	if(strpos($s,'(')!==false || strpos($s,')')!==false || strpos($s,'\\')!==false || strpos($s,"\r")!==false)
		return str_replace(array('\\','(',')',"\r"), array('\\\\','\\(','\\)','\\r'), $s);
	else
		return $s;
}

protected function _textstring($s)
{
	//格式化文本字符串（供 PDF 输出）
	if(!$this->_isascii($s))
		$s = $this->_UTF8toUTF16($s);
	return '('.$this->_escape($s).')';
}

protected function _dounderline($x, $y, $txt)
{
	//为文本绘制下划线
	$up = $this->CurrentFont['up'];
	$ut = $this->CurrentFont['ut'];
	$w = $this->GetStringWidth($txt)+$this->ws*substr_count($txt,' ');
	return sprintf('%.2F %.2F %.2F %.2F re f',$x*$this->k,($this->h-($y-$up/1000*$this->FontSize))*$this->k,$w*$this->k,-$ut/1000*$this->FontSizePt);
}

protected function _parsejpg($file)
{
	//从 JPEG 文件提取图像信息
	$a = getimagesize($file);
	if(!$a)
		$this->Error('Missing or incorrect image file: '.$file);
	if($a[2]!=2)
		$this->Error('Not a JPEG file: '.$file);
	if(!isset($a['channels']) || $a['channels']==3)
		$colspace = 'DeviceRGB';
	elseif($a['channels']==4)
		$colspace = 'DeviceCMYK';
	else
		$colspace = 'DeviceGray';
	$bpc = isset($a['bits']) ? $a['bits'] : 8;
	$data = file_get_contents($file);
	return array('w'=>$a[0], 'h'=>$a[1], 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'DCTDecode', 'data'=>$data);
}

protected function _parsepng($file)
{
	//从 PNG 文件提取图像信息
	$f = fopen($file,'rb');
	if(!$f)
		$this->Error('Can\'t open image file: '.$file);
	$info = $this->_parsepngstream($f,$file);
	fclose($f);
	return $info;
}

protected function _parsepngstream($f, $file)
{
	//校验 PNG 文件签名
	if($this->_readstream($f,8)!=chr(137).'PNG'.chr(13).chr(10).chr(26).chr(10))
		$this->Error('Not a PNG file: '.$file);

	//读取文件头数据块（IHDR）
	$this->_readstream($f,4);
	if($this->_readstream($f,4)!='IHDR')
		$this->Error('Incorrect PNG file: '.$file);
	$w = $this->_readint($f);
	$h = $this->_readint($f);
	$bpc = ord($this->_readstream($f,1));
	if($bpc>8)
		$this->Error('16-bit depth not supported: '.$file);
	$ct = ord($this->_readstream($f,1));
	if($ct==0 || $ct==4)
		$colspace = 'DeviceGray';
	elseif($ct==2 || $ct==6)
		$colspace = 'DeviceRGB';
	elseif($ct==3)
		$colspace = 'Indexed';
	else
		$this->Error('Unknown color type: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown compression method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Unknown filter method: '.$file);
	if(ord($this->_readstream($f,1))!=0)
		$this->Error('Interlacing not supported: '.$file);
	$this->_readstream($f,4);
	$dp = '/Predictor 15 /Colors '.($colspace=='DeviceRGB' ? 3 : 1).' /BitsPerComponent '.$bpc.' /Columns '.$w;

	//扫描各数据块以查找调色板、透明信息与图像数据
	$pal = '';
	$trns = '';
	$data = '';
	do
	{
		$n = $this->_readint($f);
		$type = $this->_readstream($f,4);
		if($type=='PLTE')
		{
			//读取调色板（PLTE）
			$pal = $this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='tRNS')
		{
			//读取透明信息（tRNS）
			$t = $this->_readstream($f,$n);
			if($ct==0)
				$trns = array(ord(substr($t,1,1)));
			elseif($ct==2)
				$trns = array(ord(substr($t,1,1)), ord(substr($t,3,1)), ord(substr($t,5,1)));
			else
			{
				$pos = strpos($t,chr(0));
				if($pos!==false)
					$trns = array($pos);
			}
			$this->_readstream($f,4);
		}
		elseif($type=='IDAT')
		{
			//读取图像数据块（IDAT）
			$data .= $this->_readstream($f,$n);
			$this->_readstream($f,4);
		}
		elseif($type=='IEND')
			break;
		else
			$this->_readstream($f,$n+4);
	}
	while($n);

	if($colspace=='Indexed' && empty($pal))
		$this->Error('Missing palette in '.$file);
	$info = array('w'=>$w, 'h'=>$h, 'cs'=>$colspace, 'bpc'=>$bpc, 'f'=>'FlateDecode', 'dp'=>$dp, 'pal'=>$pal, 'trns'=>$trns);
	if($ct>=4)
	{
		//提取 alpha 透明通道
		if(!function_exists('gzuncompress'))
			$this->Error('Zlib not available, can\'t handle alpha channel: '.$file);
		$data = gzuncompress($data);
		$color = '';
		$alpha = '';
		if($ct==4)
		{
			//灰度图像
			$len = 2*$w;
			for($i=0;$i<$h;$i++)
			{
				$pos = (1+$len)*$i;
				$color .= $data[$pos];
				$alpha .= $data[$pos];
				$line = substr($data,$pos+1,$len);
				$color .= preg_replace('/(.)./s','$1',$line);
				$alpha .= preg_replace('/.(.)/s','$1',$line);
			}
		}
		else
		{
			//RGB 彩色图像
			$len = 4*$w;
			for($i=0;$i<$h;$i++)
			{
				$pos = (1+$len)*$i;
				$color .= $data[$pos];
				$alpha .= $data[$pos];
				$line = substr($data,$pos+1,$len);
				$color .= preg_replace('/(.{3})./s','$1',$line);
				$alpha .= preg_replace('/.{3}(.)/s','$1',$line);
			}
		}
		unset($data);
		$data = gzcompress($color);
		$info['smask'] = gzcompress($alpha);
		$this->WithAlpha = true;
		if($this->PDFVersion<'1.4')
			$this->PDFVersion = '1.4';
	}
	$info['data'] = $data;
	return $info;
}

protected function _readstream($f, $n)
{
	//从流中读取 n 个字节
	$res = '';
	while($n>0 && !feof($f))
	{
		$s = fread($f,$n);
		if($s===false)
			$this->Error('Error while reading stream');
		$n -= strlen($s);
		$res .= $s;
	}
	if($n>0)
		$this->Error('Unexpected end of stream');
	return $res;
}

protected function _readint($f)
{
	//从流中读取一个 4 字节整数
	$a = unpack('Ni',$this->_readstream($f,4));
	return $a['i'];
}

protected function _parsegif($file)
{
	//从 GIF 文件提取信息（先转换为 PNG）
	if(!function_exists('imagepng'))
		$this->Error('GD extension is required for GIF support');
	if(!function_exists('imagecreatefromgif'))
		$this->Error('GD has no GIF read support');
	$im = imagecreatefromgif($file);
	if(!$im)
		$this->Error('Missing or incorrect image file: '.$file);
	imageinterlace($im,0);
	ob_start();
	imagepng($im);
	$data = ob_get_clean();
	imagedestroy($im);
	$f = fopen('php://temp','rb+');
	if(!$f)
		$this->Error('Unable to create memory stream');
	fwrite($f,$data);
	rewind($f);
	$info = $this->_parsepngstream($f,$file);
	fclose($f);
	return $info;
}

protected function _out($s)
{
	//向文档缓冲区追加一行内容
	if($this->state==2)
		$this->pages[$this->page] .= $s."\n";
	elseif($this->state==1)
		$this->_put($s);
	elseif($this->state==0)
		$this->Error('No page has been added yet');
	elseif($this->state==3)
		$this->Error('The document is closed');
}

protected function _put($s)
{
	$this->buffer .= $s."\n";
}

protected function _getoffset()
{
	return strlen($this->buffer);
}

protected function _newobj($n=null)
{
	//开始一个新的 PDF 对象
	if($n===null)
		$n = ++$this->n;
	$this->offsets[$n] = $this->_getoffset();
	$this->_put($n.' 0 obj');
}

protected function _putstream($data)
{
	$this->_put('stream');
	$this->_put($data);
	$this->_put('endstream');
}

protected function _putstreamobject($data)
{
	if($this->compress)
	{
		$entries = '/Filter /FlateDecode ';
		$data = gzcompress($data);
	}
	else
		$entries = '';
	$entries .= '/Length '.strlen($data);
	$this->_newobj();
	$this->_put('<<'.$entries.'>>');
	$this->_putstream($data);
	$this->_put('endobj');
}

protected function _putpage($n)
{
	$this->_newobj();
	$this->_put('<</Type /Page');
	$this->_put('/Parent 1 0 R');
	if(isset($this->PageInfo[$n]['size']))
		$this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$this->PageInfo[$n]['size'][0],$this->PageInfo[$n]['size'][1]));
	if(isset($this->PageInfo[$n]['rotation']))
		$this->_put('/Rotate '.$this->PageInfo[$n]['rotation']);
	$this->_put('/Resources 2 0 R');
	if(!empty($this->PageLinks[$n]))
	{
		$s = '/Annots [';
		foreach($this->PageLinks[$n] as $pl)
			$s .= $pl[5].' 0 R ';
		$s .= ']';
		$this->_put($s);
	}
	if($this->WithAlpha)
		$this->_put('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
	$this->_put('/Contents '.($this->n+1).' 0 R>>');
	$this->_put('endobj');
	//页面正文内容
	if(!empty($this->AliasNbPages))
		$this->pages[$n] = str_replace($this->AliasNbPages,$this->page,$this->pages[$n]);
	$this->_putstreamobject($this->pages[$n]);
	//注释（页面链接热区）
	foreach($this->PageLinks[$n] as $pl)
	{
		$this->_newobj();
		$rect = sprintf('%.2F %.2F %.2F %.2F',$pl[0],$pl[1],$pl[0]+$pl[2],$pl[1]-$pl[3]);
		$s = '<</Type /Annot /Subtype /Link /Rect ['.$rect.'] /Border [0 0 0] ';
		if(is_string($pl[4]))
			$s .= '/A <</S /URI /URI '.$this->_textstring($pl[4]).'>>>>';
		else
		{
			$l = $this->links[$pl[4]];
			if(isset($this->PageInfo[$l[0]]['size']))
				$h = $this->PageInfo[$l[0]]['size'][1];
			else
				$h = ($this->DefOrientation=='P') ? $this->DefPageSize[1]*$this->k : $this->DefPageSize[0]*$this->k;
			$s .= sprintf('/Dest [%d 0 R /XYZ 0 %.2F null]>>',$this->PageInfo[$l[0]]['n'],$h-$l[1]*$this->k);
		}
		$this->_put($s);
		$this->_put('endobj');
	}
}

protected function _putpages()
{
	$nb = $this->page;
	$n = $this->n;
	for($i=1;$i<=$nb;$i++)
	{
		$this->PageInfo[$i]['n'] = ++$n;
		$n++;
		foreach($this->PageLinks[$i] as &$pl)
			$pl[5] = ++$n;
		unset($pl);
	}
	for($i=1;$i<=$nb;$i++)
		$this->_putpage($i);
	//页面树根节点
	$this->_newobj(1);
	$this->_put('<</Type /Pages');
	$kids = '/Kids [';
	for($i=1;$i<=$nb;$i++)
		$kids .= $this->PageInfo[$i]['n'].' 0 R ';
	$kids .= ']';
	$this->_put($kids);
	$this->_put('/Count '.$nb);
	if($this->DefOrientation=='P')
	{
		$w = $this->DefPageSize[0];
		$h = $this->DefPageSize[1];
	}
	else
	{
		$w = $this->DefPageSize[1];
		$h = $this->DefPageSize[0];
	}
	$this->_put(sprintf('/MediaBox [0 0 %.2F %.2F]',$w*$this->k,$h*$this->k));
	$this->_put('>>');
	$this->_put('endobj');
}

protected function _putfonts()
{
	foreach($this->FontFiles as $file=>$info)
	{
		//字体文件嵌入
		$this->_newobj();
		$this->FontFiles[$file]['n'] = $this->n;
		$font = file_get_contents($this->fontpath.$file,true);
		if(!$font)
			$this->Error('Font file not found: '.$file);
		$compressed = (substr($file,-2)=='.z');
		if(!$compressed && isset($info['length2']))
			$font = substr($font,6,$info['length1']).substr($font,6+$info['length1']+6,$info['length2']);
		$this->_put('<</Length '.strlen($font));
		if($compressed)
			$this->_put('/Filter /FlateDecode');
		$this->_put('/Length1 '.$info['length1']);
		if(isset($info['length2']))
			$this->_put('/Length2 '.$info['length2'].' /Length3 0');
		$this->_put('>>');
		$this->_putstream($font);
		$this->_put('endobj');
	}
	foreach($this->fonts as $k=>$font)
	{
		//字符编码（差异表）
		if(isset($font['diff']))
		{
			if(!isset($this->encodings[$font['enc']]))
			{
				$this->_newobj();
				$this->_put('<</Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences ['.$font['diff'].']>>');
				$this->_put('endobj');
				$this->encodings[$font['enc']] = $this->n;
			}
		}
		//ToUnicode 字符映射表
		if(isset($font['uv']))
		{
			if(isset($font['enc']))
				$cmapkey = $font['enc'];
			else
				$cmapkey = $font['name'];
			if(!isset($this->cmaps[$cmapkey]))
			{
				$cmap = $this->_tounicodecmap($font['uv']);
				$this->_putstreamobject($cmap);
				$this->cmaps[$cmapkey] = $this->n;
			}
		}
		//字体对象
		$this->fonts[$k]['n'] = $this->n+1;
		$type = $font['type'];
		$name = $font['name'];
		if(!empty($font['subsetted']))
			$name = 'AAAAAA+'.$name;
		if($type=='Core')
		{
			//核心字体
			$this->_newobj();
			$this->_put('<</Type /Font');
			$this->_put('/BaseFont /'.$name);
			$this->_put('/Subtype /Type1');
			if($name!='Symbol' && $name!='ZapfDingbats')
				$this->_put('/Encoding /WinAnsiEncoding');
			if(isset($font['uv']))
				$this->_put('/ToUnicode '.$this->cmaps[$cmapkey].' 0 R');
			$this->_put('>>');
			$this->_put('endobj');
		}
		elseif($type=='Type1' || $type=='TrueType')
		{
			//额外的 Type1 或 TrueType/OpenType 字体
			$this->_newobj();
			$this->_put('<</Type /Font');
			$this->_put('/BaseFont /'.$name);
			$this->_put('/Subtype /'.$type);
			$this->_put('/FirstChar 32 /LastChar 255');
			$this->_put('/Widths '.($this->n+1).' 0 R');
			$this->_put('/FontDescriptor '.($this->n+2).' 0 R');
			if(isset($font['diff']))
				$this->_put('/Encoding '.$this->encodings[$font['enc']].' 0 R');
			else
				$this->_put('/Encoding /WinAnsiEncoding');
			if(isset($font['uv']))
				$this->_put('/ToUnicode '.$this->cmaps[$cmapkey].' 0 R');
			$this->_put('>>');
			$this->_put('endobj');
			//字符宽度数组
			$this->_newobj();
			$cw = &$font['cw'];
			$s = '[';
			for($i=32;$i<=255;$i++)
				$s .= $cw[chr($i)].' ';
			$this->_put($s.']');
			$this->_put('endobj');
			//字体描述符
			$this->_newobj();
			$s = '<</Type /FontDescriptor /FontName /'.$name;
			foreach($font['desc'] as $k=>$v)
				$s .= ' /'.$k.' '.$v;
			if(!empty($font['file']))
				$s .= ' /FontFile'.($type=='Type1' ? '' : '2').' '.$this->FontFiles[$font['file']]['n'].' 0 R';
			$this->_put($s.'>>');
			$this->_put('endobj');
		}
		else
		{
			//允许扩展其它字体类型
			$mtd = '_put'.strtolower($type);
			if(!method_exists($this,$mtd))
				$this->Error('Unsupported font type: '.$type);
			$this->$mtd($font);
		}
	}
}

protected function _tounicodecmap($uv)
{
	$ranges = '';
	$nbr = 0;
	$chars = '';
	$nbc = 0;
	foreach($uv as $c=>$v)
	{
		if(is_array($v))
		{
			$ranges .= sprintf("<%02X> <%02X> <%04X>\n",$c,$c+$v[1]-1,$v[0]);
			$nbr++;
		}
		else
		{
			$chars .= sprintf("<%02X> <%04X>\n",$c,$v);
			$nbc++;
		}
	}
	$s = "/CIDInit /ProcSet findresource begin\n";
	$s .= "12 dict begin\n";
	$s .= "begincmap\n";
	$s .= "/CIDSystemInfo\n";
	$s .= "<</Registry (Adobe)\n";
	$s .= "/Ordering (UCS)\n";
	$s .= "/Supplement 0\n";
	$s .= ">> def\n";
	$s .= "/CMapName /Adobe-Identity-UCS def\n";
	$s .= "/CMapType 2 def\n";
	$s .= "1 begincodespacerange\n";
	$s .= "<00> <FF>\n";
	$s .= "endcodespacerange\n";
	if($nbr>0)
	{
		$s .= "$nbr beginbfrange\n";
		$s .= $ranges;
		$s .= "endbfrange\n";
	}
	if($nbc>0)
	{
		$s .= "$nbc beginbfchar\n";
		$s .= $chars;
		$s .= "endbfchar\n";
	}
	$s .= "endcmap\n";
	$s .= "CMapName currentdict /CMap defineresource pop\n";
	$s .= "end\n";
	$s .= "end";
	return $s;
}

protected function _putimages()
{
	foreach(array_keys($this->images) as $file)
	{
		$this->_putimage($this->images[$file]);
		unset($this->images[$file]['data']);
		unset($this->images[$file]['smask']);
	}
}

protected function _putimage(&$info)
{
	$this->_newobj();
	$info['n'] = $this->n;
	$this->_put('<</Type /XObject');
	$this->_put('/Subtype /Image');
	$this->_put('/Width '.$info['w']);
	$this->_put('/Height '.$info['h']);
	if($info['cs']=='Indexed')
		$this->_put('/ColorSpace [/Indexed /DeviceRGB '.(strlen($info['pal'])/3-1).' '.($this->n+1).' 0 R]');
	else
	{
		$this->_put('/ColorSpace /'.$info['cs']);
		if($info['cs']=='DeviceCMYK')
			$this->_put('/Decode [1 0 1 0 1 0 1 0]');
	}
	$this->_put('/BitsPerComponent '.$info['bpc']);
	if(isset($info['f']))
		$this->_put('/Filter /'.$info['f']);
	if(isset($info['dp']))
		$this->_put('/DecodeParms <<'.$info['dp'].'>>');
	if(isset($info['trns']) && is_array($info['trns']))
	{
		$trns = '';
		for($i=0;$i<count($info['trns']);$i++)
			$trns .= $info['trns'][$i].' '.$info['trns'][$i].' ';
		$this->_put('/Mask ['.$trns.']');
	}
	if(isset($info['smask']))
		$this->_put('/SMask '.($this->n+1).' 0 R');
	$this->_put('/Length '.strlen($info['data']).'>>');
	$this->_putstream($info['data']);
	$this->_put('endobj');
	//软遮罩（透明度蒙版）
	if(isset($info['smask']))
	{
		$dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns '.$info['w'];
		$smask = array('w'=>$info['w'], 'h'=>$info['h'], 'cs'=>'DeviceGray', 'bpc'=>8, 'f'=>$info['f'], 'dp'=>$dp, 'data'=>$info['smask']);
		$this->_putimage($smask);
	}
	//调色板对象
	if($info['cs']=='Indexed')
		$this->_putstreamobject($info['pal']);
}

protected function _putxobjectdict()
{
	foreach($this->images as $image)
		$this->_put('/I'.$image['i'].' '.$image['n'].' 0 R');
}

protected function _putresourcedict()
{
	$this->_put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
	$this->_put('/Font <<');
	foreach($this->fonts as $font)
		$this->_put('/F'.$font['i'].' '.$font['n'].' 0 R');
	$this->_put('>>');
	$this->_put('/XObject <<');
	$this->_putxobjectdict();
	$this->_put('>>');
}

protected function _putresources()
{
	$this->_putfonts();
	$this->_putimages();
	//资源字典
	$this->_newobj(2);
	$this->_put('<<');
	$this->_putresourcedict();
	$this->_put('>>');
	$this->_put('endobj');
}

protected function _putinfo()
{
	$this->metadata['Producer'] = 'FPDF '.FPDF_VERSION;
	$this->metadata['CreationDate'] = 'D:'.@date('YmdHis');
	foreach($this->metadata as $key=>$value)
		$this->_put('/'.$key.' '.$this->_textstring($value));
}

protected function _putcatalog()
{
	$n = $this->PageInfo[1]['n'];
	$this->_put('/Type /Catalog');
	$this->_put('/Pages 1 0 R');
	if($this->ZoomMode=='fullpage')
		$this->_put('/OpenAction ['.$n.' 0 R /Fit]');
	elseif($this->ZoomMode=='fullwidth')
		$this->_put('/OpenAction ['.$n.' 0 R /FitH null]');
	elseif($this->ZoomMode=='real')
		$this->_put('/OpenAction ['.$n.' 0 R /XYZ null null 1]');
	elseif(!is_string($this->ZoomMode))
		$this->_put('/OpenAction ['.$n.' 0 R /XYZ null null '.sprintf('%.2F',$this->ZoomMode/100).']');
	if($this->LayoutMode=='single')
		$this->_put('/PageLayout /SinglePage');
	elseif($this->LayoutMode=='continuous')
		$this->_put('/PageLayout /OneColumn');
	elseif($this->LayoutMode=='two')
		$this->_put('/PageLayout /TwoColumnLeft');
}

protected function _putheader()
{
	$this->_put('%PDF-'.$this->PDFVersion);
}

protected function _puttrailer()
{
	$this->_put('/Size '.($this->n+1));
	$this->_put('/Root '.$this->n.' 0 R');
	$this->_put('/Info '.($this->n-1).' 0 R');
}

protected function _enddoc()
{
	$this->_putheader();
	$this->_putpages();
	$this->_putresources();
	//文档信息字典
	$this->_newobj();
	$this->_put('<<');
	$this->_putinfo();
	$this->_put('>>');
	$this->_put('endobj');
	//文档目录对象
	$this->_newobj();
	$this->_put('<<');
	$this->_putcatalog();
	$this->_put('>>');
	$this->_put('endobj');
	//交叉引用表
	$offset = $this->_getoffset();
	$this->_put('xref');
	$this->_put('0 '.($this->n+1));
	$this->_put('0000000000 65535 f ');
	for($i=1;$i<=$this->n;$i++)
		$this->_put(sprintf('%010d 00000 n ',$this->offsets[$i]));
	//文件尾（trailer）
	$this->_put('trailer');
	$this->_put('<<');
	$this->_puttrailer();
	$this->_put('>>');
	$this->_put('startxref');
	$this->_put($offset);
	$this->_put('%%EOF');
	$this->state = 3;
}
}
?>
