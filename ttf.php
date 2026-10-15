<?php
// ============================================================================
// 改进作者(使用Ai): yujianyue <15058593138@qq.com>
// ttf.php —— TrueType / OpenType 字体解析与子集化（纯 PHP，兼容 PHP 5.5+）
// ----------------------------------------------------------------------------
// 用途：在 FPDF/PDF_Chinese 中内嵌 UTF-8 中文字体（采用 Identity-H 编码），
//       使生成的 PDF 不再依赖阅读器端 Adobe 亚洲字体；中文/英文/emoji 混排
//       统一按“码点（grapheme）”处理，UTF-8 原生输入输出。
//
// 支持两种轮廓：
//   1) TrueType（glyf）—— 子集化后内嵌为 FontFile2 + CIDFontType2
//   2) OpenType/CFF（OTTO）—— 子集化后内嵌为 FontFile3/CIDFontType0C
//      说明：本版本对 CFF 提供“整字体嵌入”的稳定路径；glyf 提供完整紧凑
//      子集化（仅含用到的字形）。两者对外接口一致（subset() 返回紧凑映射）。
// ============================================================================

if(!class_exists('TTFontSubsetter', false))
{
class TTFontSubsetter
{
	// ---- 解析结果字段 ----
	public $data = '';          // 字体原始二进制
	public $sfnt = 0;           // sfnt 起点偏移（ttc 需偏移）
	public $tables = array();    // tag => array('offset','length','checksum')
	public $cmap = array();      // codepoint(int) => glyphIndex(int)
	public $unitsPerEm = 1000;
	public $ascent = 800;
	public $descent = -200;
	public $capHeight = 700;
	public $italicAngle = 0;
	public $fontBBox = array(0, -200, 1000, 900);
	public $isCFF = false;
	public $numGlyphs = 0;
	public $postScriptName = '';
	public $indexToLocFormat = 0;

	protected $adv = array();        // gid => array(advance, lsb)
	protected $numHMetrics = 0;
	protected $loca = array();       // gid => offset（在 glyf 表内）
	protected $glyfData = '';

	// ---- 低层读取辅助（带长度保护，避免越界 unpack 警告）----
	protected function _u16($s, $o){ $b = substr($s, $o, 2); if(strlen($b) < 2) return 0; $v = unpack('n', $b); return $v[1]; }
	protected function _s16($s, $o){ $b = substr($s, $o, 2); if(strlen($b) < 2) return 0; $v = unpack('n', $b); $v = $v[1]; return $v >= 0x8000 ? $v - 0x10000 : $v; }
	protected function _u32($s, $o){ $b = substr($s, $o, 4); if(strlen($b) < 4) return 0; $v = unpack('N', $b); $v = $v[1]; return $v < 0 ? $v + 0x100000000 : $v; }
	protected function _s32($s, $o){ $b = substr($s, $o, 4); if(strlen($b) < 4) return 0; $v = unpack('N', $b); return $v[1]; }
	protected function _fixed($s, $o){ return $this->_s32($s, $o) / 0x10000; }

	// UTF-16BE 字节串 -> ASCII（用于 name 表中 Windows/Unicode 平台的 PostScript 名）
	protected function _utf16beToAscii($s)
	{
		$out = '';
		$len = strlen($s);
		for($i = 0; $i + 1 < $len; $i += 2)
		{
			$code = (ord($s[$i]) << 8) | ord($s[$i + 1]);
			if($code == 0) continue;
			if($code < 0x80) $out .= chr($code);
			// 非 ASCII 字符直接丢弃（PostScript 名规范仅允许 ASCII 可打印字符）
		}
		return $out;
	}

	// ---- 加载字体（支持 ttc 字体集合，fontIndex 选择具体字体）----
	function load($data, $fontIndex = 0)
	{
		$this->data = $data;
		$len = strlen($data);
		$sig = substr($data, 0, 4);
		$sfnt = 0;
		if($sig === 'ttcf')
		{
			// 字体集合（TTC）：读取字体数量与各字体偏移
			$num = $this->_u32($data, 8);
			if($fontIndex < 0 || $fontIndex >= $num) $fontIndex = 0;
			$sfnt = $this->_u32($data, 12 + $fontIndex * 4);
		}
		$this->sfnt = $sfnt;
		$version = substr($data, $sfnt, 4);
		if($version !== "\x00\x01\x00\x00" && $version !== 'true' && $version !== 'OTTO')
			return false;
		$numTables = $this->_u16($data, $sfnt + 4);
		$p = $sfnt + 12;
		$this->tables = array();
		for($i = 0; $i < $numTables; $i++)
		{
			$tag = substr($data, $p, 4);
			$checksum = $this->_u32($data, $p + 4);
			$offset = $this->_u32($data, $p + 8);
			$length = $this->_u32($data, $p + 12);
			$this->tables[$tag] = array('offset' => $offset, 'length' => $length, 'checksum' => $checksum);
			$p += 16;
		}
		$this->isCFF = isset($this->tables['CFF ']);
		if(!$this->_parseHead()) return false;
		$this->_parseHhea();
		$this->_parseMaxp();
		$this->_parseHmtx();
		$this->_parseCmap();
		$this->_parseNamePost();
		if(!$this->isCFF)
			$this->_parseLoca();
		return true;
	}

	protected function _parseHead()
	{
		if(!isset($this->tables['head'])) return false;
		$t = $this->tables['head']; $d = $this->data; $o = $t['offset'];
		$this->unitsPerEm = $this->_u16($d, $o + 18);
		$this->fontBBox = array(
			$this->_s16($d, $o + 36), $this->_s16($d, $o + 38),
			$this->_s16($d, $o + 40), $this->_s16($d, $o + 42));
		$this->indexToLocFormat = $this->_s16($d, $o + 50);
		return true;
	}

	protected function _parseHhea()
	{
		if(!isset($this->tables['hhea'])) return false;
		$t = $this->tables['hhea']; $d = $this->data; $o = $t['offset'];
		$this->numHMetrics = $this->_u16($d, $o + 34);
		$this->ascent = $this->_s16($d, $o + 4);
		$this->descent = $this->_s16($d, $o + 6);
		return true;
	}

	protected function _parseMaxp()
	{
		if(!isset($this->tables['maxp'])) return false;
		$t = $this->tables['maxp']; $o = $t['offset'];
		$this->numGlyphs = $this->_u16($this->data, $o + 4);
		return true;
	}

	protected function _parseHmtx()
	{
		if(!isset($this->tables['hmtx'])) return false;
		$h = $this->tables['hmtx']; $d = $this->data; $o = $h['offset'];
		$numH = $this->numHMetrics; $ng = $this->numGlyphs;
		$this->adv = array();
		$p = $o;
		for($i = 0; $i < $numH; $i++)
		{
			$a = $this->_u16($d, $p);
			$l = $this->_s16($d, $p + 2);
			$this->adv[$i] = array($a, $l);
			$p += 4;
		}
		$lastA = isset($this->adv[$numH - 1]) ? $this->adv[$numH - 1][0] : 0;
		$lastL = isset($this->adv[$numH - 1]) ? $this->adv[$numH - 1][1] : 0;
		for($i = $numH; $i < $ng; $i++)
		{
			$l = $this->_s16($d, $p);
			$this->adv[$i] = array($lastA, $l);
			$p += 2;
		}
		return true;
	}

	protected function _parseCmap()
	{
		if(!isset($this->tables['cmap'])) return false;
		$t = $this->tables['cmap']; $d = $this->data; $o = $t['offset'];
		$num = $this->_u16($d, $o + 2);
		$best = null; $bestScore = 0;
		for($i = 0; $i < $num; $i++)
		{
			$plat = $this->_u16($d, $o + 4 + $i * 8);
			$enc = $this->_u16($d, $o + 6 + $i * 8);
			$suboff = $this->_u32($d, $o + 8 + $i * 8);
			$sub = $o + $suboff;
			$fmt = $this->_u16($d, $sub);
			$score = 0;
			if($plat == 3 && $enc == 10) $score = 4;
			elseif($plat == 3 && $enc == 1) $score = 3;
			elseif($plat == 0 && $enc == 3) $score = 2;
			elseif($plat == 0 && $enc == 4) $score = 1;
			if($score > $bestScore){ $bestScore = $score; $best = $sub; }
		}
		if($best === null) return false;
		$fmt = $this->_u16($d, $best);
		if($fmt == 4) $this->_parseCmapF4($best);
		elseif($fmt == 12) $this->_parseCmapF12($best);
		else return false;
		return true;
	}

	protected function _parseCmapF4($sub)
	{
		$d = $this->data;
		$segCountX2 = $this->_u16($d, $sub + 6);
		$segCount = $segCountX2 / 2;
		$endOff = $sub + 14;
		$startOff = $endOff + $segCountX2 + 2;
		$deltaOff = $startOff + $segCountX2;
		$rangeOff = $deltaOff + $segCountX2;
		$glyphOff = $rangeOff + $segCountX2;
		for($i = 0; $i < $segCount; $i++)
		{
			$end = $this->_u16($d, $endOff + $i * 2);
			$start = $this->_u16($d, $startOff + $i * 2);
			$delta = $this->_s16($d, $deltaOff + $i * 2);
			$ro = $this->_u16($d, $rangeOff + $i * 2);
			if($start == 0xFFFF) continue;
			if($ro == 0)
			{
				for($c = $start; $c <= $end; $c++)
					$this->cmap[$c] = (($c + $delta) & 0xFFFF);
			}
			else
			{
				for($c = $start; $c <= $end; $c++)
				{
					$gio = $glyphOff + $ro + ($c - $start) * 2;
					$gid = $this->_u16($d, $gio);
					if($gid != 0) $gid = ($gid + $delta) & 0xFFFF;
					$this->cmap[$c] = $gid;
				}
			}
		}
	}

	protected function _parseCmapF12($sub)
	{
		$d = $this->data;
		$ngroups = $this->_u32($d, $sub + 12);
		$p = $sub + 16;
		for($i = 0; $i < $ngroups; $i++)
		{
			$start = $this->_u32($d, $p);
			$end = $this->_u32($d, $p + 4);
			$sgin = $this->_u32($d, $p + 8);
			$p += 12;
			if($start == 0xFFFFFFFF) continue;
			// 防止异常超大段导致死循环（正常字体段范围有限）
			if($end - $start > 0x10000) continue;
			for($c = $start; $c <= $end; $c++)
				$this->cmap[$c] = $sgin + ($c - $start);
		}
	}

	protected function _parseNamePost()
	{
		$d = $this->data;
		if(isset($this->tables['name']))
		{
			$t = $this->tables['name']; $o = $t['offset'];
			$count = $this->_u16($d, $o + 2);
			$storage = $o + $this->_u16($d, $o + 4);
			// 每条 name 记录 12 字节：platformID/encodingID/languageID/nameID/length/offset
			// 分别位于记录内 +0/+2/+4/+6/+8/+10
			$macName = ''; $winName = '';
			for($i = 0; $i < $count; $i++)
			{
				$base = $o + 6 + $i * 12;
				$pid = $this->_u16($d, $base);        // platformID
				$nid = $this->_u16($d, $base + 6);    // nameID
				$len = $this->_u16($d, $base + 8);    // length
				$soff = $this->_u16($d, $base + 10);  // offset
				if($nid != 6) continue;
				$raw = substr($d, $storage + $soff, $len);
				if($pid == 1)
					$macName = $raw;                                   // Mac 平台：ASCII
				elseif($pid == 3 || $pid == 0)
					$winName = $this->_utf16beToAscii($raw);            // Windows/Unicode：UTF-16BE
			}
			if($macName !== '') $this->postScriptName = $macName;
			elseif($winName !== '') $this->postScriptName = $winName;
		}
		$this->postScriptName = trim($this->postScriptName);
		if($this->postScriptName === '')
			$this->postScriptName = 'UnknownFont';
		if(isset($this->tables['post']))
		{
			$t = $this->tables['post']; $o = $t['offset'];
			$this->italicAngle = $this->_fixed($d, $o + 4);
		}
		if(isset($this->tables['OS/2']))
		{
			$t = $this->tables['OS/2']; $o = $t['offset'];
			$ver = $this->_u16($d, $o);
			$this->ascent = $this->_s16($d, $o + 68);
			$this->descent = $this->_s16($d, $o + 70);
			if($ver >= 2)
				$this->capHeight = $this->_s16($d, $o + 88);
			else
				$this->capHeight = intval($this->ascent * 0.7);
		}
	}

	protected function _parseLoca()
	{
		if($this->isCFF) return;
		$g = $this->tables['glyf']; $l = $this->tables['loca'];
		$d = $this->data; $o = $l['offset']; $ng = $this->numGlyphs;
		$fmt = $this->indexToLocFormat;
		$this->loca = array();
		if($fmt == 0)
			for($i = 0; $i <= $ng; $i++)
				$this->loca[$i] = $this->_u16($d, $o + $i * 2) * 2;
		else
			for($i = 0; $i <= $ng; $i++)
				$this->loca[$i] = $this->_u32($d, $o + $i * 4);
		$this->glyfData = substr($d, $g['offset'], $g['length']);
	}

	// ---- 对外查询接口 ----
	function glyphIndex($cp)
	{
		return isset($this->cmap[$cp]) ? $this->cmap[$cp] : 0;
	}

	function advance($gid)
	{
		if(!isset($this->adv[$gid]))
			return array(0, 0);
		return $this->adv[$gid];
	}

	// ---- 子集化入口：返回 array('data'=>二进制, 'map'=>origGid=>newGid, 'type'=>, 'adv'=>newGid=>[a,l]) ----
	function subset($chars)
	{
		if($this->isCFF)
			return $this->_subsetCFF($chars);
		return $this->_subsetGlyf($chars);
	}

	// 递归收集 glyph 及其组件（复合字形）所引用的全部 gid
	protected function _collectGlyph($g, &$used)
	{
		if($g == 0 || isset($used[$g])) return;
		$used[$g] = 1;
		if($this->isCFF) return;
		$off = $this->loca[$g];
		$len = $this->loca[$g + 1] - $off;
		if($len <= 0) return;
		$gd = substr($this->glyfData, $off, $len);
		$nc = $this->_s16($gd, 0);
		if($nc < 0)
		{
			// 复合字形：解析每个组件的 glyph index 并递归
			$p = 10;
			do
			{
				$flags = $this->_u16($gd, $p); $p += 2;
				$gi = $this->_u16($gd, $p); $p += 2;
				$this->_collectGlyph($gi, $used);
				if($flags & 0x0001) $p += 4; else $p += 2;       // ARG_1_AND_2_ARE_WORDS
				if($flags & 0x0008) $p += 2;                       // WE_HAVE_A_SCALE
				elseif($flags & 0x0040) $p += 4;                    // X_AND_Y_SCALE
				elseif($flags & 0x0080) $p += 8;                    // TWO_BY_TWO
			} while($flags & 0x0020);                               // WE_HAVE_MORE_COMPONENTS
		}
	}

	// 重建复合字形：把组件引用的 origGid 改写为 newGid
	protected function _rebuildComposite($gd, $map)
	{
		$nc = $this->_s16($gd, 0);
		if($nc >= 0) return $gd; // 简单字形不改
		$out = substr($gd, 0, 10);
		$p = 10;
		do
		{
			$flags = $this->_u16($gd, $p); $p += 2;
			$gi = $this->_u16($gd, $p); $p += 2;
			$newGi = isset($map[$gi]) ? $map[$gi] : 0;
			$out .= pack('nn', $flags, $newGi);
			if($flags & 0x0001){ $out .= substr($gd, $p, 4); $p += 4; }
			else { $out .= substr($gd, $p, 2); $p += 2; }
			if($flags & 0x0008){ $out .= substr($gd, $p, 2); $p += 2; }
			elseif($flags & 0x0040){ $out .= substr($gd, $p, 4); $p += 4; }
			elseif($flags & 0x0080){ $out .= substr($gd, $p, 8); $p += 8; }
		} while($flags & 0x0020);
		if($flags & 0x0100) // WE_HAVE_INSTRUCTIONS
		{
			$instrLen = $this->_u16($gd, $p); $p += 2;
			$out .= pack('n', $instrLen) . substr($gd, $p, $instrLen);
			$p += $instrLen;
		}
		if($p < strlen($gd)) $out .= substr($gd, $p);
		return $out;
	}

	// ---- TrueType（glyf）完整紧凑子集化 ----
	protected function _subsetGlyf($chars)
	{
		$this->_parseLoca();
		$used = array(0 => 0);
		foreach($chars as $cp)
		{
			$g = $this->glyphIndex($cp);
			$this->_collectGlyph($g, $used);
		}
		$orig = array_keys($used);
		sort($orig, SORT_NUMERIC);
		$map = array();
		foreach($orig as $i => $g) $map[$g] = $i;
		$newGid = count($orig);

		$newGlyf = '';
		$newLoca = array(0);
		$advOut = '';
		$newAdv = array();
		$pos = 0;
		foreach($orig as $g)
		{
			$off = $this->loca[$g];
			$len = $this->loca[$g + 1] - $off;
			$gd = ($len > 0) ? substr($this->glyfData, $off, $len) : '';
			$gd = $this->_rebuildComposite($gd, $map);
			$newGlyf .= $gd;
			$pos += strlen($gd);
			$newLoca[] = $pos;
			list($a, $l) = $this->adv[$g];
			$advOut .= pack('nn', $a, $l);
			$newAdv[$map[$g]] = array($a, $l);
		}

		// 重建各项表
		$tables = array();
		// head：indexToLocFormat=1，checkSumAdjustment=0
		$head = substr($this->data, $this->tables['head']['offset'], $this->tables['head']['length']);
		$head = substr_replace($head, "\x00\x01", 50, 2);   // indexToLocFormat = 1
		$head = substr_replace($head, "\x00\x00\x00\x00", 8, 4); // checkSumAdjustment = 0
		$tables['head'] = $head;
		// hhea：numberOfHMetrics = newGid
		$hhea = substr($this->data, $this->tables['hhea']['offset'], $this->tables['hhea']['length']);
		$hhea = substr_replace($hhea, pack('n', $newGid), 34, 2);
		$tables['hhea'] = $hhea;
		// maxp：numGlyphs = newGid
		$maxp = substr($this->data, $this->tables['maxp']['offset'], $this->tables['maxp']['length']);
		$maxp = substr_replace($maxp, pack('n', $newGid), 4, 2);
		$tables['maxp'] = $maxp;
		// loca（4 字节）
		$locaOut = '';
		foreach($newLoca as $v) $locaOut .= pack('N', $v);
		$tables['loca'] = $locaOut;
		// glyf
		$tables['glyf'] = $newGlyf;
		// hmtx
		$tables['hmtx'] = $advOut;
		// cmap：format 6（仅 BMP）
		$tables['cmap'] = $this->_buildCmapF6($chars, $map);
		// name：保留原样
		$tables['name'] = substr($this->data, $this->tables['name']['offset'], $this->tables['name']['length']);
		// post：改为 format 3（省去字形名）
		$tables['post'] = pack('N', 0x00030000) . pack('N', 0) . pack('n', 0) . pack('n', 0)
						. pack('N', 0) . pack('N', 0) . pack('N', 0) . pack('N', 0) . pack('N', 0);
		// OS/2、cvt 、fpgm、prep：保留原样（hint 指令需要）
		foreach(array('OS/2', 'cvt ', 'fpgm', 'prep') as $tg)
		{
			if(isset($this->tables[$tg]))
				$tables[$tg] = substr($this->data, $this->tables[$tg]['offset'], $this->tables[$tg]['length']);
		}

		$font = $this->_assemble($tables);
		return array('data' => $font, 'map' => $map, 'type' => 'glyf', 'adv' => $newAdv);
	}

		protected function _buildCmapF6($chars, $map)
		{
			$bmp = array();
			foreach($chars as $cp)
			{
				if($cp >= 0 && $cp <= 0xFFFF)
					$bmp[$cp] = true;
			}
			$cpList = array_keys($bmp);
			sort($cpList, SORT_NUMERIC);
			if(count($cpList))
			{
				$first = $cpList[0];
				$last = $cpList[count($cpList) - 1];
				$count = $last - $first + 1;
				$arr = '';
				for($i = 0; $i < $count; $i++)
				{
					$cp = $first + $i;
					$orig = isset($this->cmap[$cp]) ? $this->cmap[$cp] : 0;
					$ng = isset($map[$orig]) ? $map[$orig] : 0;
					$arr .= pack('n', $ng);
				}
				$sub = pack('nnnn', 6, 10 + $count * 2, 0, $first) . pack('n', $count) . $arr;
			}
			else
			{
				$sub = pack('nnnn', 6, 10, 0, 0) . pack('n', 0);
			}
			// cmap 表头（version=0, numTables=1）+ 一条记录（platform=3, encoding=1）
			// 目录共 4 + 1*8 = 12 字节，子表偏移为 12
			$cmap = pack('nn', 0, 1) . pack('nnN', 3, 1, 12) . $sub;
			return $cmap;
		}

	// ---- OpenType/CFF：本版本整字体嵌入（稳定路径），origGid 即 newGid ----
	protected function _subsetCFF($chars)
	{
		$t = $this->tables['CFF '];
		$data = substr($this->data, $t['offset'], $t['length']);
		$map = array(0 => 0);
		foreach($chars as $cp)
		{
			$g = $this->glyphIndex($cp);
			$map[$g] = $g;
		}
		// adv 以 origGid 为键（identity）
		$adv = $this->adv;
		return array('data' => $data, 'map' => $map, 'type' => 'cff', 'adv' => $adv);
	}

	// ---- 表校验和（4 字节分组累加，超限回卷）----
	protected function _calcChecksum($t)
	{
		$len = strlen($t);
		$sum = 0;
		for($i = 0; $i < $len; $i += 4)
		{
			$w = substr($t, $i, 4);
			if(strlen($w) < 4) $w = str_pad($w, 4, "\0");
			$v = unpack('N', $w); $v = $v[1];
			if($v < 0) $v += 0x100000000;
			$sum = ($sum + $v) & 0xFFFFFFFF;
		}
		return $sum;
	}

	// ---- 组装 sfnt（TrueType 轮廓），并重算 head 的 checkSumAdjustment ----
	protected function _assemble($tables)
	{
		$tags = array_keys($tables);
		sort($tags);
		$num = count($tags);
		$offset = 12 + $num * 16;
		$dir = '';
		foreach($tags as $tag)
		{
			$td = $tables[$tag];
			$len = strlen($td);
			$dir .= $tag . pack('NNN', $this->_calcChecksum($td), $offset, $len);
			$padv = (4 - ($len % 4)) % 4;
			$offset += $len + $padv;
		}
		$body = '';
		foreach($tags as $tag)
		{
			$td = $tables[$tag];
			$len = strlen($td);
			$body .= $td;
			$padv = (4 - ($len % 4)) % 4;
			if($padv) $body .= str_repeat("\0", $padv);
		}
		// searchRange / entrySelector / rangeShift
		$maxPow = 0;
		while((1 << ($maxPow + 1)) <= $num) $maxPow++;
		$searchRange = (1 << $maxPow) * 16;
		$entrySelector = $maxPow;
		$rangeShift = $num * 16 - $searchRange;
		$file = pack('N', 0x00010000) . pack('nnnn', $num, $searchRange, $entrySelector, $rangeShift) . $dir . $body;
		// 计算全部表校验和，写入 head.checkSumAdjustment
		$sum = 0;
		foreach($tags as $tag)
			$sum = ($sum + $this->_calcChecksum($tables[$tag])) & 0xFFFFFFFF;
		$adjust = (0xB1B0AFBA - $sum) & 0xFFFFFFFF;
		$p = 12;
		for($i = 0; $i < $num; $i++)
		{
			$t = substr($file, $p, 4);
			$off = unpack('N', substr($file, $p + 8, 4)); $off = $off[1];
			if($t === 'head')
			{
				$file = substr_replace($file, pack('N', $adjust), $off + 8, 4);
				break;
			}
			$p += 16;
		}
		return $file;
	}
}
}
?>
