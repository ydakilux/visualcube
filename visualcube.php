<?php
/*
	File: visualcube.php
	Date: 02 Apr 2010
	Author(s): Conrad Rider (www.crider.co.uk)
	Contributors: Shotaro Makisumi <smakisumi@gmail.com>, Jaume Casado Ruiz <minterior@gmail.com>
	Description: Main script to generate cube images

	This file is part of VisualCube.

	VisualCube is free software: you can redistribute it and/or modify
	it under the terms of the GNU Lesser General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	VisualCube is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Lesser General Public License for more details.

	You should have received a copy of the GNU Lesser General Public License
	along with Foobar.  If not, see <http://www.gnu.org/licenses/>.

	Copyright (C) 2010 Conrad Rider

	TODO:
	* Automatic Permutation Arrows
	* Other puzzles

	CHANGES:
	(Version 0.5.5 to 0.5.6)
	* Modified options to attempt to fix rendering issues in ImageMagick v6.9.x
	(Version 0.5.4 to 0.5.5)
	* Changed to using style attribute to fix transparrancy issues
	(Version 0.5.3 to 0.5.4)
	* Added configurable DB host
	* Added configurable path to ImageMagick convert binary
	(Version 0.5.2 to 0.5.3)
	* Fixed links on API page
	* Changed default format to SVG, avoiding ImageMagick failures
	* Addressed PHP warnings and fixed typos in the code
	(Version 0.5.1 to 0.5.2)
	* Separate configuration file
	* Separate API definition file - easier to provide custom API page
	(Version 0.3.0 to 0.4.0)
	* Algs applicable to NxNxN cubes
	* Wider range of stage masks, with the ability to rotate them
	* Style variables configurable via cookies


*/

	// Import configuration values
	require 'visualcube_config.php';

	global
		$DB_HOST,
		$DB_NAME,
		$DB_USERNAME,
		$DB_PASSWORD,
		$MAX_PZL_DIM,
		$ENABLE_COOKIES,
		$ENABLE_CACHE,
		$CACHE_IMG_SIZE_LIMIT,
		$DEFAULTS;


	// VisualCube version
	$VERSION = "0.5.5";


	// Causes cube svg to be outputted as XML for inspection
	$DEBUG = false;
	// Do not display errors
//	if (!$DEBUG) error_reporting(0);


	// ----------------------[ API Page ]-----------------------

	// If no format specified, display API page
	if(!array_key_exists('fmt', $_REQUEST)){

		include 'visualcube_api.php';

	// Otherwise render a cube
	}else{
		// Check cache for image and return if it exists in cache
		if($ENABLE_CACHE){
			// Connect to db
			$mysql_con = mysqli_connect($DB_HOST, $DB_USERNAME, $DB_PASSWORD, $DB_NAME) or die("Connect Error: " . mysqli_connect_error());

			$hash = md5($_SERVER['QUERY_STRING']);
			$imgdata = get_arrays($mysql_con, "SELECT fmt, req, rcount, img FROM vcache WHERE hash='$hash'");
			// Verify query strings are equal (deals with unlikely, but possible hash collisions)
			if($imgdata && count($imgdata) > 0 && $imgdata[0]['req'] == $_SERVER['QUERY_STRING']){
				display_img($imgdata[0]['img'], $imgdata[0]['fmt']);
				// Increment access count
				mysqli_query($mysql_con, "UPDATE vcache SET rcount=rcount+1 WHERE hash='$hash'");
				// Disconnect from db
				mysqli_close();
				return;
			}
		}


		// Otherwise generate image


		// -----------------[ Constants ]-----------------

		// Faces
		$U = 0; $R = 1; $F = 2; $D = 3; $L = 4; $B = 5; $N = 6; $O = 7; $T = 8;


		// Colour constants
		$BLACK  = '000000';
		$DGREY  = '404040';
		$GREY   = '808080';
		$SILVER = 'BFBFBF';
		$WHITE  = 'FFFFFF';
		$YELLOW = 'FEFE00';
		$RED    = 'EE0000';//'FE0000';
		$ORANGE = 'FFA100';//'FE8600';
		$BLUE   = '0000F2';
		$GREEN  = '00D800';//'00F300';
		$PURPLE = 'A83DD9';
		$PINK   = 'F33D7B';

		// Other colour schemes
		// Array('FFFF00', 'FF0000', '0000FF', 'FFFFFF', 'FF7F00', '00FF00'); // Basic
		// Array('EFEF00', 'C80000', '0000B6', 'F7F7F7', 'FFA100', '00B648'); // Cubestation
		// Array('EFFF01', 'FF0000', '1600FF', 'FEFFFC', 'FF8000', '047F01'); // cube.rider
		// Array('FEFE00', 'FE0000', '0000F2', 'FEFEFE', 'FE8600', '00F300'); // alg.garron

		// Name colour mapping
		$NAME_COL = Array(
			'black'  => $BLACK,
			'dgrey'  => $DGREY,
			'grey'   => $GREY,
			'silver' => $SILVER,
			'white'  => $WHITE,
			'yellow' => $YELLOW,
			'red'    => $RED,
			'orange' => $ORANGE,
			'blue'   => $BLUE,
			'green'  => $GREEN,
			'purple' => $PURPLE,
			'pink'   => $PINK);

		// Abbreviation colour mapping
		$ABBR_COL = Array(
			'n' => $BLACK,
			'd' => $DGREY,
			'l' => $GREY,
			's' => $SILVER,
			'w' => $WHITE,
			'y' => $YELLOW,
			'r' => $RED,
			'o' => $ORANGE,
			'b' => $BLUE,
			'g' => $GREEN,
			'm' => $PURPLE,
			'p' => $PINK,
			't' => 't'); // Transparent

		// Default colour scheme
		$DEF_SCHEME = Array ($YELLOW, $RED, $BLUE, $WHITE, $ORANGE, $GREEN, $DGREY, $GREY, 't');
		// $DEF_SCHEME = Array ($WHITE, $RED, $GREEN, $BLUE, $ORANGE, $YELLOW, $GREY, $SILVER, 't'); // Japanese scheme

		// Corresponding mappings from colour code to face id
		$DEF_SCHCODE = Array('y', 'r', 'b', 'w', 'o', 'g',);
		//$DEF_SCHCODE = Array('w', 'r', 'g', 'b', 'o', 'y',); // Japanese scheme


		// -----------------------[ User Parameters ]--------------------

		// Retrieve format from user, default to first in list otherwise
		$LEGAL_FMT = Array ('gif', 'png', 'svg', 'jpg', 'jpe', 'jpeg', 'tiff', 'ico');
		$fmt = $LEGAL_FMT[0];
		if(array_key_exists('fmt', $_REQUEST) || array_key_exists('fmt', $DEFAULTS)){
			$fmt = array_key_exists('fmt', $_REQUEST) ? $_REQUEST['fmt'] : $DEFAULTS['fmt'];
			if(!in_array($fmt, $LEGAL_FMT))
				$fmt = $LEGAL_FMT[0];
			else{
				if($fmt == 'jpeg' || $fmt == 'jpe') $fmt = 'jpg';
			}
		}

		// Default rotation sequence
		$rtn = Array(Array(1, 45), Array(0, -34));
		// Get rotation from request (or cookie)
		if(array_key_exists('r', $_REQUEST) || array_key_exists('r', $DEFAULTS)
		|| ($ENABLE_COOKIES && isset($_COOKIE['vc_r']) && $_COOKIE['vc_r'] != '')){
			$_r = array_key_exists('r', $_REQUEST) ? $_REQUEST['r'] :
			($ENABLE_COOKIES && isset($_COOKIE['vc_r']) && $_COOKIE['vc_r'] != '' ?
				$_COOKIE['vc_r'] : $DEFAULTS['r']);
			preg_match_all('/([xyz])(\-?[0-9][0-9]?[0-9]?)/', $_r, $matches);
			for($i = 0; $i < count($matches[0]); $i++){
				switch($matches[1][$i]){
					case 'x' : $rtn_[$i][0] = 0; break;
					case 'y' : $rtn_[$i][0] = 1; break;
					case 'z' : $rtn_[$i][0] = 2; break;
					default : break;
				}
				$rtn_[$i][1] = $matches[2][$i];
			}
			if($rtn_) $rtn = $rtn_;
		}

		// Retrieve cube Dimension
		$dim = $DEFAULTS['pzl'];
		if(array_key_exists('pzl', $_REQUEST) && is_numeric($_REQUEST['pzl'])
		&& $_REQUEST['pzl'] > 0 && $_REQUEST['pzl'] <= $MAX_PZL_DIM)
			$dim = $_REQUEST['pzl'];

		// Default scheme
		$scheme = $DEF_SCHEME;
		// Default mapping from colour code to face id
		$schcode = $DEF_SCHCODE;
		// Retrieve colour scheme from request (or cookie)
		if(array_key_exists('sch', $_REQUEST) || array_key_exists('sch', $DEFAULTS)
		|| ($ENABLE_COOKIES && isset($_COOKIE['vc_sch']) && $_COOKIE['vc_sch'] != '')){
			// Retrieve from cookie or 'sch' variable
			$sd = array_key_exists('sch', $_REQUEST) ? $_REQUEST['sch'] :
			($ENABLE_COOKIES && isset($_COOKIE['vc_sch']) && $_COOKIE['vc_sch'] != '' ?
				$_COOKIE['vc_sch'] : $DEFAULTS['sch']);
			if(preg_match('/^[ndlswyrogbpmt]{6}$/', $sd)){
				for($i = 0; $i < 6; $i++){
					$scheme[$i] = $ABBR_COL[$sd[$i]];
					$schcode[$i] = $sd[$i];
				}
			}
			else{
				$cols = preg_split('/,/', $sd);
				if(count($cols) == 6){
					$cok = true;
					for($i = 0; $i < 6; $i++){
						$scheme[$i] = parse_col($cols[$i]);
						if(!$cols[$i]) $cok = false;
					}
					if(!$cok) $scheme = $DEF_SCHEME;
				}
			}
		}

		// Retrieve size from user
		$size = $DEFAULTS['size']; // default
		if(array_key_exists('size', $_REQUEST) && is_numeric($_REQUEST['size'])){
			$size = $_REQUEST['size'];
			if($size < 0) $size = 0;
			if($size > 1024) $size = 1024;
		}

		// Retrieve dist variable - projection distance (how close the eye is to the cube)
		$dist = $DEFAULTS['dist']; // default dist parameter
		if(array_key_exists('dist', $_REQUEST) || ($ENABLE_COOKIES && isset($_COOKIE['vc_dist']))){
			$dist_ = array_key_exists('dist', $_REQUEST) ? $_REQUEST['dist'] : $_COOKIE['vc_dist'];
			if(is_numeric($dist_)) $dist = $dist_ < 1 ? 1 : ($dist_ > 100 ? 100 : $dist_);
		}

		// Retrieve view variable
		$view = $DEFAULTS['view'];
		if(array_key_exists('view', $_REQUEST)){
			$view = $_REQUEST['view'];
		}

		// Retrieve background colour from request (or cookies)
		$bg = parse_col($DEFAULTS['bg']);
		if(!$bg) $bg = 'FFFFFF';
		if(array_key_exists('bg', $_REQUEST) || ($ENABLE_COOKIES && isset($_COOKIE['vc_bg']))){
			$bg_ = array_key_exists('bg', $_REQUEST) ? $_REQUEST['bg'] : $_COOKIE['vc_bg'];
			if($bg_ == "t") $bg = null;
			else{
				$bg_ = parse_col($bg_);
				if($bg_) $bg = $bg_;
			}
		}

		// Retrieve cube colour from request (or cookies)
		$cc = $view == 'trans' ? $SILVER : parse_col($DEFAULTS['cc']);
		if(array_key_exists('cc', $_REQUEST) || ($ENABLE_COOKIES && isset($_COOKIE['vc_cc']))){
			$cc_ = array_key_exists('cc', $_REQUEST) ? $_REQUEST['cc'] : $_COOKIE['vc_cc'];
			$cc_ = parse_col($cc_);
			if($cc_) $cc = $cc_;
		}
		$cc = !$cc ? $BLACK : $cc;

		// Retrieve cube opacity from request (or cookies)
		$co = $view == 'trans' ? 50 : $DEFAULTS['co'];
		if(array_key_exists('co', $_REQUEST) || ($ENABLE_COOKIES && isset($_COOKIE['vc_co']))){
			$co_ = array_key_exists('co', $_REQUEST) ? $_REQUEST['co'] : $_COOKIE['vc_co'];
			if(preg_match('/^[0-9][0-9]?$/', $co_)) $co = $co_;
		}
		$co = !is_numeric($co) || $co < 0 || $co > 100 ? 100 : $co;

		// Retrieve face opacity from request (or cookies)
		$fo = $DEFAULTS['fo'];
		if(!is_numeric($fo) || $fo < 0 || $fo > 100)
			$fo = 100;
		if(array_key_exists('fo', $_REQUEST) || ($ENABLE_COOKIES && isset($_COOKIE['vc_fo']))){
			$fo_ = array_key_exists('fo', $_REQUEST) ? $_REQUEST['fo'] : $_COOKIE['vc_fo'];
			if(preg_match('/^[0-9][0-9]?$/', $fo_)) $fo = $fo_;
		}


		// Create default face defs
		for($fc = 0; $fc < 6; $fc++){ for($i = 0; $i < $dim * $dim; $i++)
			$facelets[$fc * $dim * $dim + $i] = $fc;
		}
		// Retrieve colour def
		// This overrides face def and makes the $scheme variable redundant (ie, gets reset to default)
		$using_cols = false;
		$uf = array_key_exists('fc', $_REQUEST) ? $_REQUEST['fc'] : (!array_key_exists('fd', $_REQUEST) ? $DEFAULTS['fc'] : '');
		if(preg_match('/^[ndlswyrobgmpt]+$/', $uf)){
			$using_cols = true;
			$scheme = $DEF_SCHEME;
			$nf = strlen($uf);
			for($fc = 0; $fc < 6; $fc++){ for($i = 0; $i < $dim * $dim; $i++){
				// Add user defined face
				if($fc * $dim *$dim + $i < $nf)
					$facelets[$fc * $dim * $dim + $i] = $uf[$fc * $dim *$dim + $i];
				// Otherwise use scheme code
				else
					$facelets[$fc * $dim * $dim + $i] = $schcode[$fc];
			}}
		}
		// Retrieve facelet def
		if(!$uf){ $uf = array_key_exists('fd', $_REQUEST) ? $_REQUEST['fd'] : $DEFAULTS['fd'];
		if(preg_match('/^[udlrfbnot]+$/', $uf)){
			// Map from face names to numeric face ID
			$fd_map = Array('u' => $U, 'r' => $R, 'f' => $F, 'd' => $D, 'l' => $L, 'b' => $B, 'n' => $N, 'o' => $O, 't' => $T);
			$nf = strlen($uf);
			for($fc = 0; $fc < 6; $fc++){ for($i = 0; $i < $dim * $dim; $i++){
				// Add user defined face
				if($fc * $dim *$dim + $i < $nf)
					$facelets[$fc * $dim * $dim + $i] = $fd_map[$uf[$fc * $dim *$dim + $i]];
				// Otherwise default to a blank/transparent face
				else $facelets[$fc * $dim *$dim + $i] = $view == 'trans' ? $T : $N;
			}}
		}}
		// Retrieve stage variable
		if(array_key_exists('stage', $_REQUEST) || array_key_exists('stage', $DEFAULTS)){
			$stage = array_key_exists('stage', $_REQUEST) ? $_REQUEST['stage'] : $DEFAULTS['stage'];
			// Extract rotation sequence if present
			$p = strrpos($stage, '-');
			$st_rtn = '';
			if($p > 0){
				$st_rtn = urldecode(substr($stage, $p+1));
				$stage = substr($stage, 0, $p);
			}
			// Stage Definitions
			$mask = '';
			if($dim == 3){
				switch($stage){
					case 'fl' :
				$mask = "000000000000000111000000111111111111000000111000000111";
				break;
					case 'f2l' :
				$mask = "000000000000111111000111111111111111000111111000111111";
				break;
					case 'll' :
				$mask = "111111111111000000111000000000000000111000000111000000";
				break;
					case 'cll' :
				$mask = "101010101101000000101000000000000000101000000101000000";
				break;
					case 'ell' :
				$mask = "010111010010000000010000000000000000010000000010000000";
				break;
					case 'oll' :
				$mask = "111111111000000000000000000000000000000000000000000000";
				break;
					case 'ocll' :
				$mask = "101010101000000000000000000000000000000000000000000000";
				break;
					case 'oell' :
				$mask = "010111010000000000000000000000000000000000000000000000";
				break;
					case 'coll' :
				$mask = "111111111101000000101000000000000000101000000101000000";
				break;
					case 'ocell' :
				$mask = "111111111010000000010000000000000000010000000010000000";
				break;
					case 'wv' :
				$mask = "111111111000111111000111111111111111000111111000111111";
				break;
					case 'vh' :
				$mask = "010111010000111111000111111111111111000111111000111111";
				break;
					case 'els' :
				$mask = "010111010000111011000111110110111111000111111000111111";
				break;
					case 'cls' :
				$mask = "111111111000111111000111111111111111000111111000111111";
				break;
					case 'cmll' :
				$mask = "101000101101111111101101101101101101101111111101101101";
				break;
					case 'cross' :
				$mask = "000000000000010010000010010010111010000010010000010010";
				break;
					case 'f2l_3' :
				$mask = "000000000000110110000011011011111010000010010000010010";
				break;
					case 'f2l_2' :
				$mask = "000000000000011011000010010010111111000110110000111111";
				break;
					case 'f2l_sm' :
				$mask = "000000000000110110000011011011111110000110110000011011";
				break;
					case 'f2l_1' :
				$mask = "000000000000011011000110110110111111000111111000111111";
				break;
					case 'f2b' :
				$mask = "000000000000111111000101101101101101000111111000101101";
				break;
					case 'line' :
				$mask = "000000000000000000000010010010010010000000000000010010";
				break;
					case '2x2x2' :
				$mask = "000000000000110110000011011011011000000000000000000000";
				break;
					case '2x2x3' :
				$mask = "000000000000110110000111111111111000000011011000000000";
				break;
				}
			}else if($dim == 2){
				switch($stage){
					case 'fl' :
				$mask = "000000110011111100110011";
				break;
					case 'll' :
				$mask = "111111001100000011001100";
				break;
					case 'oll' :
				$mask = "111100000000111100000000";
				break;
				}
			}

			// Apply alg to mask if defined
			if($mask && $st_rtn != ''){
				require_once "cube_lib.php";
				$mask = fcs_doperm($mask, fcs_format_alg($st_rtn), $dim);
			}

			// Apply mask to face def
			if($mask){
				for($i = 0; $i < $dim * $dim * 6; $i++){
					$facelets[$i] = $mask[$i] == 0 ?
						($view == 'trans' ? ($using_cols ? 't' : $T) :
						($using_cols ? 'l' : $N)) : $facelets[$i];
				}
			}
		}

		// Retrieve alg def
		if(array_key_exists('alg', $_REQUEST) || array_key_exists('case', $_REQUEST)
		|| array_key_exists('alg', $DEFAULTS) || array_key_exists('case', $DEFAULTS)){
			require_once "cube_lib.php";
			if(array_key_exists('alg', $_REQUEST)) $alg = $_REQUEST['alg']; else $alg = $DEFAULTS['alg'];
			if(array_key_exists('case', $_REQUEST)) $case = $_REQUEST['case']; else $case = $DEFAULTS['case'];
			$alg = fcs_format_alg(urldecode($alg));
			$case = fcs_format_alg(urldecode($case));
//			$facelets = facelet_cube(case_cube($alg), $dim, $facelets); // old 3x3 alg system
			$facelets = fcs_doperm($facelets, $case . ' ' . $alg, $dim); // new NxN facelet permute
		}

		// Retrieve arrow defn's
		if(array_key_exists('arw', $_REQUEST)){
			$astr = preg_split('/,/', $_REQUEST['arw']);
			$i = 0;
			foreach($astr as $a){
				$a_ = parse_arrow($a, $dim);
				if($a_) $arrows[$i++] = $a_;
			}
		}

		// Retrieve default arrow colour (default: pale warm yellow to match reference style)
		$ac = '808080';
		if(array_key_exists('ac', $_REQUEST)){
			$ac_ = parse_col($_REQUEST['ac']);
			if($ac_ && $ac_ != 't') $ac = $ac_;
		}

		// Retrieve move rotation arrow defn's (e.g. move=U,Rprime,x)
		$move_arrows = Array();
		if(array_key_exists('move', $_REQUEST)){
			$mstr = preg_split('/,/', $_REQUEST['move']);
			foreach($mstr as $m){
				$m = trim($m);
				if($m === '') continue;
				$ma = parse_move($m, $ac);
				if($ma) $move_arrows[] = $ma;
			}
		}

		// Arrow curvature: arrowbulge=0.0 (flat) to 1.0 (very curved), default 0.5
		$arrow_bulge = 0.5;
		if(array_key_exists('arrowbulge', $_REQUEST)){
			$ab = floatval($_REQUEST['arrowbulge']);
			$arrow_bulge = max(0.0, min(1.0, $ab));
		}

		// Arrow highlight: on by default; arrowhl=0 disables the 3D sheen effect
		$arrow_hl = !(array_key_exists('arrowhl', $_REQUEST) && $_REQUEST['arrowhl'] == '0');

		// ---------------[ 3D Cube Generator properties ]---------------

		// Outline width
		$OUTLINE_WIDTH = 0.94;

		// Stroke width
		$sw = 0;

		// Viewport
		$ox = -0.9;
		$oy = -0.9;
		$vw = 1.8;
		$vh = 1.8;

		// ------------------[ 3D Cube Generator ]-----------------------

		// Set up cube for OLL view if specified
		if($view == 'plan'){
			$rtn = Array(Array(0, -90));
		}

		// All cube face points
		$p = Array();
		// Translation vector to centre the cube
		$t = Array(-$dim/2, -$dim/2, -$dim/2);
		// Translation vector to move the cube away from viewer
		$zpos = Array(0, 0, $dist);
		// Rotation vectors to track visibility of each face
		$rv = Array(Array(0, -1, 0), Array(1, 0, 0), Array(0, 0, -1), Array(0, 1, 0), Array(-1, 0, 0), Array(0, 0, 1));
		for($fc = 0; $fc < 6; $fc++){
			for($i = 0; $i <= $dim; $i++){
				for($j = 0; $j <= $dim; $j++){
					switch($fc){
						case $U : $p[$fc][$i][$j] = Array(       $i,    0, $dim - $j); break;
						case $R : $p[$fc][$i][$j] = Array(     $dim,   $j,        $i); break;
						case $F : $p[$fc][$i][$j] = Array(       $i,   $j,         0); break;
						case $D : $p[$fc][$i][$j] = Array(       $i, $dim,        $j); break;
						case $L : $p[$fc][$i][$j] = Array(        0,   $j, $dim - $i); break;
						case $B : $p[$fc][$i][$j] = Array($dim - $i,   $j,      $dim); break;
					}
					// Now scale and tranform point to ensure size/pos independent of dim
					$p[$fc][$i][$j] = translate($p[$fc][$i][$j], $t);
					$p[$fc][$i][$j] = scale($p[$fc][$i][$j], 1 / $dim);
					// Rotate cube as per perameter settings
					foreach($rtn as $rn){
						$p[$fc][$i][$j] = rotate($p[$fc][$i][$j], $rn[0], M_PI * $rn[1]/180);
					}
					// Move cube away from viewer
					$p[$fc][$i][$j] = translate($p[$fc][$i][$j], $zpos);
					// Finally project the 3D points onto 2D
					$p[$fc][$i][$j] = project($p[$fc][$i][$j], $zpos[2]);
				}
			}
			// Rotate rotation vectors
			foreach($rtn as $rn){
				$rv[$fc] = rotate($rv[$fc], $rn[0], M_PI * $rn[1]/180);
			}
		}

		// Sort render order (crappy bubble sort)
		$ro = Array(0, 1, 2, 3, 4, 5);
		for($i = 0; $i < 5; $i++){ for($j = 0; $j < 5; $j++){
			if($rv[$ro[$j]][2] < $rv[$ro[$j+1]][2]){
				$t = $ro[$j]; $ro[$j] = $ro[$j+1]; $ro[$j+1] = $t; }
		}}

		// Cube diagram SVG XML
		$cube = "<?xml version='1.0' standalone='no'?>
<!DOCTYPE svg PUBLIC '-//W3C//DTD SVG 1.1//EN'
'http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd'>

<svg version='1.1' xmlns='http://www.w3.org/2000/svg'
	width='$size' height='$size'
	viewBox='$ox $oy $vw $vh'>\n";

	// Draw background
	if($bg) $cube .= "\t<rect fill='#$bg' x='$ox' y='$oy' width='$vw' height='$vh'/>\n";

		// Transparancy background rendering
		if($co < 100){
			// Create polygon for each background facelet (transparency only)
			$cube .= "\t<g style='opacity:".($fo/100).";stroke-opacity:0.5;stroke-width:$sw;stroke-linejoin:round'>\n";
			for($ri = 0; $ri < 3; $ri++){
				$cube .= facelet_svg($ro[$ri]);
			}
			$cube .= "\t</g>\n";

			// Create outline for each background face (transparency only)
			$cube .= "\t<g style='stroke-width:0.1;stroke-linejoin:round;opacity:".($co/100)."'>\n";
			for($ri = 0; $ri < 3; $ri++)
				$cube .= outline_svg($ro[$ri]);
			$cube .= "\t</g>\n";
		}

		// Create outline for each visible face
		$cube .= "\t<g style='stroke-width:0.1;stroke-linejoin:round;opacity:".($co/100)."'>\n";
		for($ri = 3; $ri < 6; $ri++){
			if(face_visible($ro[$ri], $rv) || $co < 100)
				$cube .= outline_svg($ro[$ri]);
		}
		$cube .= "\t</g>\n";

		// Create polygon for each visible facelet
		$cube .= "\t<g style='opacity:".($fo/100).";stroke-opacity:0.5;stroke-width:$sw;stroke-linejoin:round'>\n";
		for($ri = 3; $ri < 6; $ri++){
			if(face_visible($ro[$ri], $rv) || $co < 100)
				$cube .= facelet_svg($ro[$ri]);
		}
		$cube .= "\t</g>\n";

		// Create OLL view guides
		if($view == 'plan'){
			$cube .= "\t<g style='opacity:".($fo/100).";stroke-opacity:1;stroke-width:0.02;stroke-linejoin:round'>\n";
			$toRender = Array($F, $L, $B, $R);
			foreach($toRender as $fc)
				$cube .= oll_svg($fc);
			$cube .= "\t</g>\n";
		}

		// Draw Arrows
		if(isset($arrows)){
			$awidth = 0.12 / $dim;
			$cube .= "\t<g style='opacity:1;stroke-opacity:1;stroke-width:$awidth;stroke-linecap:round'>\n";
			foreach($arrows as $i => $a){
				$cube .= gen_arrow($i, $a[0], $a[1], $a[2], $a[4], array_key_exists(3, $a)?$a[3]:$ac);
			}
			$cube .= "\t</g>\n";
		}

		// Draw move rotation arrows
		if(!empty($move_arrows)){
			foreach($move_arrows as $ma){
				$cube .= gen_move_arrow($ma[0], $ma[1], $ma[2], $ma[3], $arrow_bulge, $arrow_hl, isset($ma[4]) ? $ma[4] : '');
			}
		}

		// Debug overlay: number each sticker on each visible face.
		// Use ?numbered=1 (or =U, =UFR, etc.) to enable.
		//   1..d² per face, numbered row-major in $p[face][r][c] indexing
		//   with r ∈ [0..d-1] (row index of sticker) and c ∈ [0..d-1] (col index).
		//   Sticker (r,c) centre is at the centroid of the four corner points
		//   p[face][r][c], p[face][r+1][c], p[face][r+1][c+1], p[face][r][c+1].
		// Sticker numbering overlay
		//   ?numbers=1            -> show face letter + digit (F1, F2 ...) on all visible faces
		//   ?numbers=UFR          -> only the listed faces (case-insensitive)
		//   ?numbers=0 (or absent)-> disabled
		//   Legacy alias: ?numbered=... still accepted.
		$numbers_req = null;
		if(array_key_exists('numbers',  $_REQUEST)) $numbers_req = $_REQUEST['numbers'];
		elseif(array_key_exists('numbered', $_REQUEST)) $numbers_req = $_REQUEST['numbered'];
		if($numbers_req !== null && $numbers_req !== '' && $numbers_req !== '0' && strtolower($numbers_req) !== 'false'){
			$which = strtoupper($numbers_req);
			if($which === '1' || $which === 'TRUE') $which = 'UFRLBD';
			$face_letter = array($U=>'U', $R=>'R', $F=>'F', $D=>'D', $L=>'L', $B=>'B');
			$cube .= "\t<g>\n";
			foreach($face_letter as $fid => $letter){
				if(strpos($which, $letter) === false) continue;
				if(!face_visible($fid, $rv)) continue;
				$n = 1;
				for($r = 0; $r < $dim; $r++){
					for($c = 0; $c < $dim; $c++){
						$p00 = $p[$fid][$r  ][$c  ];
						$p10 = $p[$fid][$r+1][$c  ];
						$p11 = $p[$fid][$r+1][$c+1];
						$p01 = $p[$fid][$r  ][$c+1];
						$cx_s = ($p00[0]+$p10[0]+$p11[0]+$p01[0]) / 4;
						$cy_s = ($p00[1]+$p10[1]+$p11[1]+$p01[1]) / 4;
						$ed1 = sqrt(($p10[0]-$p00[0])*($p10[0]-$p00[0]) + ($p10[1]-$p00[1])*($p10[1]-$p00[1]));
						$ed2 = sqrt(($p01[0]-$p00[0])*($p01[0]-$p00[0]) + ($p01[1]-$p00[1])*($p01[1]-$p00[1]));
						$ed = ($ed1 + $ed2) / 2;
						$radius = $ed * 0.34;          // a bit bigger to fit letter+digit
						$fs     = $ed * 0.38;
						// Two separate <text> elements (each a single ASCII glyph) so the
						// PNG rasterizer's font fallback handles each glyph independently.
						$gap   = $fs * 0.05;
						$x_let = $cx_s - $fs * 0.30 + $gap * 0;
						$x_dig = $cx_s + $fs * 0.30 - $gap * 0;
						$ty    = $cy_s + $fs * 0.35;   // baseline offset
						$cube .= sprintf("\t\t<circle cx='%.4f' cy='%.4f' r='%.4f' fill='#ffffff' stroke='#000000' stroke-width='%.4f'/>\n",
							$cx_s, $cy_s, $radius, $ed * 0.025);
						$cube .= sprintf("\t\t<text x='%.4f' y='%.4f' font-size='%.4f' font-family='Arial,sans-serif' font-weight='bold' text-anchor='middle' fill='#000000' stroke='none'>%s</text>\n",
							$x_let, $ty, $fs, $letter);
						$cube .= sprintf("\t\t<text x='%.4f' y='%.4f' font-size='%.4f' font-family='Arial,sans-serif' font-weight='bold' text-anchor='middle' fill='#000000' stroke='none'>%d</text>\n",
							$x_dig, $ty, $fs, $n);
						$n++;
					}
				}
			}
			$cube .= "\t</g>\n";
		}

		$cube .= "</svg>\n";





		// Display cube
		if($DEBUG) echo $cube;
		else{
			$img = $fmt != 'svg' ? convert($cube, $fmt, $size) : $cube;
			display_img($img, $fmt);

			// Cache image if enabled
			if($ENABLE_CACHE && !array_key_exists("nocache", $_REQUEST) && strlen($img) < $CACHE_IMG_SIZE_LIMIT){
				$req = mysqli_real_escape_string($mysql_con, $_SERVER['QUERY_STRING']);
				$rfr = mysqli_real_escape_string($mysql_con, $_SERVER['HTTP_REFERER']);
				$hash = md5($req);
				$img = mysqli_real_escape_string($mysql_con, $img);
				mysqli_query($mysql_con, "INSERT INTO vcache(hash, fmt, req, rfr, rcount, img) ".
						"VALUES ('$hash', '$fmt', '$req', '$rfr', 1, '$img')");
				// Disconnect from db
				mysqli_close();
			}
		}
	}


	// -----------------[ User input functions ]----------------------

	// Parse colour value
	function parse_col($col){
		global $NAME_COL, $ABBR_COL;
		// As an abbriviation
		if(preg_match('/^[ndlswyrogbpmt]$/', $col))
			return $ABBR_COL[$col];
		// As a name
		if(array_key_exists($col, $NAME_COL))
			return $NAME_COL[$col];
		// As 12-bit colour
		if(preg_match('/^[0-9a-fA-F]{3}$/', $col))
			return $col[0].$col[0].$col[1].$col[1].$col[2].$col[2];
		// As 24-bit colour
		if(preg_match('/^[0-9a-fA-F]{6}$/', $col))
			return $col;
		// Otherwise fail
		return null;
	}

	// Parse arrow definition
	function parse_arrow($str, $dim){
		$parts = preg_split('/-/', $str);
		$fcodes = array('U' => 0, 'R' => 1, 'F' => 2, 'D' => 3, 'L' => 4, 'B' => 5);
		if(count($parts) == 0) return null;
		if(!preg_match_all('/([URFDLB])([0-9]+)/', $parts[0], $split) || count($split) < 2) return null;
		$arrow = array();
		$arrow[4] = 1;
		for($i = 0; $i < 3; $i++){
			if($i == 2 && count($split[1]) < 3){
				$arrow[2] = null;
				break;
			}
			else	$arrow[2][3] = 2;
			$arrow[$i][0] = $fcodes[$split[1][$i]];
			$fn = $split[2][$i]; $fn = $fn >= $dim * $dim ? $dim * $dim - 1 : $fn;
			$arrow[$i][1] = $fn % $dim;
			$arrow[$i][2] = floor($fn / $dim);
		}
		// Parse remainder
		for($i = 1; $i < count($parts); $i++){
			if(preg_match('/^i[0-9]+$/', $parts[$i]) && $arrow[2]){
				$arrow[2][3] = substr($parts[$i],1) / 5;
				$arrow[2][3] = $arrow[2][3] > 10 ? 10 : $arrow[2][3]; // Var range = 0 to 50, default 10
			}
			else if(preg_match('/^s[0-9]+$/', $parts[$i])){
				$arrow[4] = substr($parts[$i],1) / 10;
				$arrow[4] = $arrow[4] > 2 ? 2 : $arrow[4]; // Var range = 0 to 20, default 10
			}
			else{
				$ac_ = parse_col($parts[$i]);
				if($ac_) $arrow[3] = $ac_;
			}
		}
		return $arrow;
	}

	// Insert space in default fd/fc variables
	function insert_space($in, $dim){
		$out = '';
		$dim *= $dim;
		for($i = 0; $i < 6; $i++){
			$out .= substr($in, $dim * $i, $dim) . ' ';
		}
		return $out;
	}


	// -------------------[ 3D Geometry Functions ]--------------------

	// Move point by translation vector
	function translate($p, $t){
		$p[0] += $t[0];
		$p[1] += $t[1];
		$p[2] += $t[2];
		return $p;
	}

	function scale($p, $f){
		$p[0] *= $f;
		$p[1] *= $f;
		$p[2] *= $f;
		return $p;
	}

	// Scale point relative to position vector
	function trans_scale($p, $v, $f){
		// Translate each facelet to cf
		$iv = Array(-$v[0], -$v[1], -$v[2]);
		return translate(scale(translate($p, $iv), $f), $v);
	}

	function rotate($p, $ax, $an){
		$np = Array($p[0], $p[1], $p[2]);
		switch($ax){
			case 0 :
				$np[2] = $p[2] * cos($an) - $p[1] * sin($an);
				$np[1] = $p[2] * sin($an) + $p[1] * cos($an);
				break;
			case 1 :
				$np[0] =   $p[0] * cos($an) + $p[2] * sin($an);
				$np[2] = - $p[0] * sin($an) + $p[2] * cos($an);
				break;
			case 2 :
				$np[0] = $p[0] * cos($an) - $p[1] * sin($an);
				$np[1] = $p[0] * sin($an) + $p[1] * cos($an);
				break;
		}
		return $np;
	}

	// Project 3D points onto a 2D plane
	function project($p, $d){
		return Array(
			$p[0] * $d / $p[2],
			$p[1] * $d / $p[2],
			$p[2] // Maintain z coordinate to allow use of rendering tricks
		);
	}

	// Returns whether a face is visible
	function face_visible($face, $rv){
		return $rv[$face][2] < -0.105;
	}




	// ---------------------------[ Rendering Functions ]----------------------------

	// Returns svg for a cube outline
	function outline_svg($fc){
		global $p, $dim, $cc, $OUTLINE_WIDTH;
		return "\t\t<polygon fill='#$cc' stroke='#$cc' points='".
			$p[$fc][   0][   0][0]*$OUTLINE_WIDTH.','.$p[$fc][   0][   0][1]*$OUTLINE_WIDTH.' '.
			$p[$fc][$dim][   0][0]*$OUTLINE_WIDTH.','.$p[$fc][$dim][   0][1]*$OUTLINE_WIDTH.' '.
			$p[$fc][$dim][$dim][0]*$OUTLINE_WIDTH.','.$p[$fc][$dim][$dim][1]*$OUTLINE_WIDTH.' '.
			$p[$fc][   0][$dim][0]*$OUTLINE_WIDTH.','.$p[$fc][   0][$dim][1]*$OUTLINE_WIDTH."'/>\n";
	}

	// Returns svg for a faces facelets
	function facelet_svg($fc){
		global $p, $dim;
		$svg = '';
		for($i = 0; $i < $dim; $i++){
			for($j = 0; $j < $dim; $j++){
				// Find centre point of facelet
				$cf = Array(($p[$fc][$j  ][$i  ][0] + $p[$fc][$j+1][$i+1][0])/2,
					($p[$fc][$j  ][$i  ][1] + $p[$fc][$j+1][$i+1][1])/2, 0);
				// Scale points in towards centre
				$p1 = trans_scale($p[$fc][$j  ][$i  ], $cf, 0.85);
				$p2 = trans_scale($p[$fc][$j+1][$i  ], $cf, 0.85);
				$p3 = trans_scale($p[$fc][$j+1][$i+1], $cf, 0.85);
				$p4 = trans_scale($p[$fc][$j  ][$i+1], $cf, 0.85);
				// Generate facelet polygon
				$svg .= gen_facelet($p1, $p2, $p3, $p4, $fc * $dim * $dim + $i * $dim + $j);
			}
		}
		return $svg;
	}

	// Renders the top rim of the R U L and B faces out from side of cube
	function oll_svg($fc){
		global $p, $dim, $rv;
		$svg = '';
		// Translation vector, to move faces out
		$tv1 = scale($rv[$fc], 0.00);
		$tv2 = scale($rv[$fc], 0.20);
		$i = 0;
		for($j = 0; $j < $dim; $j++){
				// Find centre point of facelet
				$cf = Array(($p[$fc][$j  ][$i  ][0] + $p[$fc][$j+1][$i+1][0])/2,
					($p[$fc][$j  ][$i  ][1] + $p[$fc][$j+1][$i+1][1])/2, 0);
				// Scale points in towards centre and skew
				$p1 = translate(trans_scale($p[$fc][$j  ][$i  ], $cf, 0.94), $tv1);
				$p2 = translate(trans_scale($p[$fc][$j+1][$i  ], $cf, 0.94), $tv1);
				$p3 = translate(trans_scale($p[$fc][$j+1][$i+1], $cf, 0.94), $tv2);
				$p4 = translate(trans_scale($p[$fc][$j  ][$i+1], $cf, 0.94), $tv2);
				// Generate facelet polygon
				$svg .= gen_facelet($p1, $p2, $p3, $p4, $fc * $dim * $dim + $i * $dim + $j);

		}
		return $svg;
	}

	/** Generates a polygon SVG tag for cube facelets */
	function gen_facelet($p1, $p2, $p3, $p4, $seq){
		global $ABBR_COL, $facelets, $scheme, $using_cols, $cc, $T;
		$fcol = $using_cols ? ($facelets[$seq] == 't' ? 't' : $ABBR_COL[$facelets[$seq]])
		                    : ($facelets[$seq] == $T ? 't' : $scheme[$facelets[$seq]]);
		return "\t\t<polygon fill='#".
			($fcol == 't' ? '000000' : $fcol)."' stroke='#$cc' ".
			($fcol == 't' ? "opacity='0' " : ' ' )."points='".
				$p1[0].','.$p1[1].' '.
				$p2[0].','.$p2[1].' '.
				$p3[0].','.$p3[1].' '.
				$p4[0].','.$p4[1]."'/>\n";
	}

	// Generates svg for an arrow pointing from sticker s1 to s2
	function gen_arrow($id, $s1, $s2, $sv, $sc, $col){
		global $p, $dim;
		if($col == 't') return;
		// Find centre point of each facelet
		$p1 = Array(($p[$s1[0]][$s1[1]][$s1[2]][0] + $p[$s1[0]][$s1[1]+1][$s1[2]+1][0])/2,
			($p[$s1[0]][$s1[1]][$s1[2]][1] + $p[$s1[0]][$s1[1]+1][$s1[2]+1][1])/2, 0);
		$p2 = Array(($p[$s2[0]][$s2[1]][$s2[2]][0] + $p[$s2[0]][$s2[1]+1][$s2[2]+1][0])/2,
			($p[$s2[0]][$s2[1]][$s2[2]][1] + $p[$s2[0]][$s2[1]+1][$s2[2]+1][1])/2, 0);
		// Find midpoint between p1 and p2
		$cp = Array(($p1[0] + $p2[0])/2, ($p1[1] + $p2[1])/2, 0);
		// Shorten arrows towards midpoint according to config
		$p1 = trans_scale($p1, $cp, $sc);
		$p2 = trans_scale($p2, $cp, $sc);
		if($sv){
			$pv = Array(($p[$sv[0]][$sv[1]][$sv[2]][0] + $p[$sv[0]][$sv[1]+1][$sv[2]+1][0])/2,
				($p[$sv[0]][$sv[1]][$sv[2]][1] + $p[$sv[0]][$sv[1]+1][$sv[2]+1][1])/2, 0);
			// Project via point double dist from centre point
			$pv = trans_scale($pv, $cp, $sv[3]);
		}
		// Calculate arrow rotation
		$p_ = $sv ? $pv : $p1;
		$rt = $p_[1] > $p2[1] ? 270 : 90;
		if($p2[0]-$p_[0] != 0){
			$rt = rad2deg(atan(($p2[1]-($p_[1]))/($p2[0]-$p_[0])));
			$rt = ($p_[0] > $p2[0]) ? $rt + 180 : $rt;
		}
		return '		<path d="M '.$p1[0].','.$p1[1].' '.(isset($pv)?'Q '.$pv[0].','.$pv[1]:'L').' '.$p2[0].','.$p2[1].'"
			style="fill:none;stroke:#'.$col.';stroke-opacity:1" />
		<path transform=" translate('.$p2[0].','.$p2[1].') scale('.(0.033 / $dim).') rotate('.$rt.')"
			d="M 5.77,0.0 L -2.88,5.0 L -2.88,-5.0 L 5.77,0.0 z"
			style="fill:#'.$col.';stroke-width:0;stroke-linejoin:round"/>'."\n";
	}

	/**
	 * Parse a move string into [face_id, ccw, color].
	 * Supports: U D R L F B u d r l f b M E S x y z
	 * Suffix: prime or ' = CCW, 2 = double (returns both CW entries), nothing = CW
	 * Returns null if unrecognised.
	 */
	function parse_move($str, $default_col = null){
		global $U, $R, $F, $D, $L, $B, $GREY;

		// Normalise: replace 'prime' suffix with apostrophe
		$str = preg_replace('/prime/i', "'", $str);

		// Extract optional colour after '-'
		$col = $default_col !== null ? $default_col : $GREY;
		if(preg_match('/^(.+)-(.+)$/', $str, $cm)){
			$c = parse_col($cm[2]);
			if($c){ $col = $c; $str = $cm[1]; }
		}

		// Determine double move
		$double = false;
		if(substr($str, -1) === '2'){ $double = true; $str = substr($str, 0, -1); }

		// Determine CCW (prime)
		$ccw = false;
		if(substr($str, -1) === "'"){  $ccw = true;  $str = substr($str, 0, -1); }

		// Map move letter(s) to face and canonical direction
		// Each entry: [face_id, flip_ccw]
		// flip_ccw=true means the "standard CW" for that face looks CCW visually on that face
		$map = Array(
			'U'  => Array($U, false),
			'u'  => Array($U, false),
			'Uw' => Array($U, false),
			'D'  => Array($D, true),
			'd'  => Array($D, true),
			'Dw' => Array($D, true),
			'R'  => Array($R, false),
			'r'  => Array($R, false),
			'Rw' => Array($R, false),
			'L'  => Array($L, false),
			'l'  => Array($L, false),
			'Lw' => Array($L, false),
			'F'  => Array($F, false),
			'f'  => Array($F, false),
			'Fw' => Array($F, false),
			'B'  => Array($B, true),
			'b'  => Array($B, true),
			'Bw' => Array($B, true),
			'M'  => Array($L, true),
			'E'  => Array($D, true),
			'S'  => Array($F, false),
			'x'  => Array($R, false),
			'y'  => Array($U, false),
			'z'  => Array($F, false),
		);

		if(!array_key_exists($str, $map)) return null;
		$face    = $map[$str][0];
		$flip    = $map[$str][1];
		// XOR: if face is naturally flipped AND user asked for CCW, result is CW, etc.
		$final_ccw = $ccw XOR $flip;

		// 5th element: the bare move token (no prime / no double / no colour).
		// Used by gen_move_arrow to dispatch rotation moves (x/y/z/M/E/S) and
		// wide moves (u/d/r/l/f/b, Uw/Dw/...) to their own arrow styles.
		$kind = $str;

		if($double){
			return Array($face, false, $col, true, $kind);
		}
		return Array($face, $final_ccw, $col, false, $kind);
	}

	/**
	 * Generates a fat rotation arc arrow SVG for a face move.
	 * Uses a quadratic bezier curve whose endpoints are the midpoints of the two
	 * "lateral" face edges (perpendicular to the outward normal), with a control
	 * point pushed outward to create the arc bulge.
	 */
	/**
	 * Draws a half-circle glass arrow lying flat on the U face.
	 * The arc is parametrized in U-face local coordinates (u,v in [0,dim])
	 * then bilinearly mapped to the projected face quadrilateral on screen.
	 * Uses a single SVG path with cubic bezier curves for smooth edges.
	 */
	/**
	 * D-move arrow: a glass-style arrow that runs along the bottom row of the F face
	 * and continues onto the bottom row of the R face, indicating the direction the
	 * bottom-row stickers travel during a D rotation.
	 * D (CW)  → arrow points from F-bottom toward R-bottom (left → right across the bottom edge)
	 * D' (CCW)→ reversed
	 */
	function gen_glass_d_wrap_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		// 4-anchor smooth curved glass arrow for D move.
		//   Anchors (sticker centres):
		//     A0 = F3 = centre of F bottom-left sticker  (p[F][0][d-1])
		//     A1 = F9 = centre of F bottom-right sticker (p[F][d-1][d-1])
		//     A2 = R3 = centre of R bottom-back sticker  (p[R][0][d-1])
		//     A3 = R6 = centre of R bottom-middle sticker(p[R][1][d-1])
		//   Path goes A0 -> A1 -> A2 -> A3 (D CW); reversed for D'.
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($F, 0, $d - 1);        // F3
		$A1 = $sticker_centre($F, $d - 1, $d - 1);   // F9
		$A2 = $sticker_centre($R, 0, $d - 1);        // R3
		$A3 = $sticker_centre($R, 1, $d - 1);        // R6

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		// Sticker width estimate (using U-face diagonal — matches U/L/R thickness)
		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_f = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_f / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		// Centripetal Catmull-Rom sampling through anchors
		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- D glass curved arrow F3 -> F9 -> R3 -> R6 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	function angle_between($ax, $ay, $bx, $by){
		$dot = $ax*$bx + $ay*$by;
		$dot = max(-1.0, min(1.0, $dot));
		return acos($dot);
	}

	/**
	 * L/R move arrow: a glass-style vertical arrow drawn on the F face's left or
	 * right column, indicating how the F-column stickers travel during the move.
	 *
	 * Convention:
	 *   L  (CW from L's POV): F-left column stickers move DOWNWARD  (toward D)
	 *   L' (CCW)            : F-left column stickers move UPWARD    (toward U)
	 *   R  (CW from R's POV): F-right column stickers move UPWARD   (toward U)
	 *   R' (CCW)            : F-right column stickers move DOWNWARD (toward D)
	 *
	 * Because F face is always fully visible in the default view, the arrow is
	 * always visible regardless of whether L/R themselves are visible.
	 *
	 * @param string $side  'L' or 'R'
	 * @param bool   $ccw   prime (counter-clockwise) variant
	 * @param bool   $hl    highlight (currently unused, reserved)
	 */
	function gen_glass_lr_column_arrow($side, $ccw, $hl){
		global $p, $dim, $F, $U;

		$d = $dim;
		// L variant: 4-anchor smooth curved glass arrow.
		//   Anchors (sticker centres):
		//     A0 = U1 = centre of U back-left corner sticker  (p[U][0][0] region)
		//     A1 = U3 = centre of U front-left corner sticker (p[U][0][d-1] region)
		//     A2 = F1 = centre of F top-left corner sticker   (p[F][0][0] region)
		//     A3 = F2 = centre of F top-middle sticker        (p[F][0][1] region)
		//   Path goes A0 -> A1 -> A2 -> A3 (L CW); reversed for L'.
		if($side == 'L'){
			// Helper: centre of sticker (r,c) on a face
			$sticker_centre = function($face, $r, $c) use (&$p){
				$q00 = $p[$face][$r  ][$c  ];
				$q10 = $p[$face][$r+1][$c  ];
				$q11 = $p[$face][$r+1][$c+1];
				$q01 = $p[$face][$r  ][$c+1];
				return array(
					($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
					($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
				);
			};

			// Determine which (r,c) corresponds to each visual sticker.
			// U face row-major: U1=(0,0), U2=(0,1), U3=(0,2), U4=(1,0), ...
			// F face row-major: F1=(0,0), F2=(0,1), F3=(0,2), F4=(1,0), ...
			$A0 = $sticker_centre($U, 0, 1);         // U2: U back-edge, second sticker
			$A1 = $sticker_centre($U, 0, $d - 1);    // U3: front-left corner of U
			$A2 = $sticker_centre($F, 0, 0);         // F1: top-left corner of F
			$A3 = $sticker_centre($F, 0, $d - 1);    // F3: top-right corner of F

			$anchors = array($A0, $A1, $A2, $A3);
			if($ccw){
				$anchors = array_reverse($anchors);
			}

			// Sticker width estimate (using diagonal of U face)
			$u_BL = $p[$U][0][0];
			$u_FR = $p[$U][$d][$d];
			$diag_u = sqrt(
				($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
				($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
			);
			$sticker_w = $diag_u / $d / 1.414;
			$thick    = $sticker_w * 0.55;
			$half_t   = $thick / 2;
			$head_w   = $thick * 1.6;
			$head_len = $thick * 1.8;

			// Sample a Catmull-Rom spline through the 4 anchors.
			// Use centripetal Catmull-Rom (alpha=0.5) for stable curvature at corners.
			$alpha = 0.5;
			$cr_points = array();   // duplicate endpoints for endpoint tangents
			$cr_points[] = $anchors[0];
			foreach($anchors as $a) $cr_points[] = $a;
			$cr_points[] = $anchors[count($anchors)-1];

			$N_per_seg = 24;
			$samples  = array();
			$tangents = array();
			$tj = function($ti, $pi, $pj) use ($alpha){
				$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
				$dist = sqrt($dx*$dx + $dy*$dy);
				if($dist < 1e-9) $dist = 1e-9;
				return $ti + pow($dist, $alpha);
			};
			for($i = 0; $i < count($anchors) - 1; $i++){
				$P0 = $cr_points[$i];
				$P1 = $cr_points[$i+1];
				$P2 = $cr_points[$i+2];
				$P3 = $cr_points[$i+3];
				$t0 = 0.0;
				$t1 = $tj($t0, $P0, $P1);
				$t2 = $tj($t1, $P1, $P2);
				$t3 = $tj($t2, $P2, $P3);
				$is_last = ($i == count($anchors) - 2);
				$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
				for($k = 0; $k <= $last_k; $k++){
					$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
					// Catmull-Rom (Barry-Goldman) value + derivative
					$A1c = array(
						($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
						($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
					);
					$A2c = array(
						($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
						($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
					);
					$A3c = array(
						($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
						($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
					);
					$B1c = array(
						($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
						($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
					);
					$B2c = array(
						($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
						($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
					);
					$C  = array(
						($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
						($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
					);
					$samples[] = $C;
					// Finite-difference tangent (cheap and good enough)
					$eps = ($t2 - $t1) * 1e-3;
					$tp  = max($t1, min($t2, $t + $eps));
					$A1d = array(
						($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
						($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
					);
					$A2d = array(
						($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
						($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
					);
					$A3d = array(
						($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
						($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
					);
					$B1d = array(
						($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
						($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
					);
					$B2d = array(
						($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
						($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
					);
					$Cd  = array(
						($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
						($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
					);
					$tx = $Cd[0] - $C[0];
					$ty = $Cd[1] - $C[1];
					$tl = sqrt($tx*$tx + $ty*$ty);
					if($tl < 1e-9) $tl = 1;
					$tangents[] = array($tx/$tl, $ty/$tl);
				}
			}
			$N = count($samples) - 1;
			$p_tip = $samples[$N];

			// Find shaft end (leave space for arrow head before p_tip)
			$shaft_end_idx = $N;
			for($k = $N; $k >= 0; $k--){
				$dx = $p_tip[0] - $samples[$k][0];
				$dy = $p_tip[1] - $samples[$k][1];
				if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
			}
			$tip_back     = $samples[$shaft_end_idx];
			$tip_back_tan = $tangents[$shaft_end_idx];

			// Build offset outlines
			$left_pts  = array();
			$right_pts = array();
			for($k = 0; $k <= $shaft_end_idx; $k++){
				$nx = -$tangents[$k][1];
				$ny =  $tangents[$k][0];
				$bx = $samples[$k][0];
				$by = $samples[$k][1];
				$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
				$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
			}
			$tn_x = -$tip_back_tan[1];
			$tn_y =  $tip_back_tan[0];
			$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
			$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

			// Smooth helper (quadratic-bezier chain through points)
			$smooth = function($pts, $reverse = false){
				$n = count($pts);
				if($reverse) $pts = array_reverse($pts);
				$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
				for($i = 1; $i < $n - 1; $i++){
					$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
					$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
					$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
				}
				$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
				return $s;
			};

			$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
			$path_d .= $smooth($left_pts);
			$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
				$base_l[0], $base_l[1],
				$p_tip[0],  $p_tip[1],
				$base_r[0], $base_r[1]);
			$path_d .= " " . $smooth($right_pts, true);
			$path_d .= " Z";

			$stroke_w = $thick * 0.30;
			$gloss_w  = $thick * 0.18;

			$svg  = "\t\t<!-- L glass curved arrow U2 -> U3 -> F1 -> F3 -->\n";
			$svg .= "\t\t<path d=\"{$path_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

			// Gloss centerline (small inset from both ends)
			$g_in = max(2, intval($N * 0.08));
			$gs = $g_in;
			$ge = max($gs, $shaft_end_idx - $g_in);
			if($ge > $gs){
				$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
				for($k = $gs+1; $k <= $ge; $k++){
					$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
				}
				$svg .= "\t\t<path d=\"{$g_d}\"\n";
				$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
			}

			return $svg;
		}

		// R variant: 4-anchor smooth curved glass arrow (mirror of L).
		//   Anchors (sticker centres):
		//     A0 = F9 = centre of F bottom-right corner sticker (p[F][2][2] region)
		//     A1 = F7 = centre of F top-right corner sticker    (p[F][2][0] region)
		//     A2 = U9 = centre of U front-right corner sticker  (p[U][2][2] region)
		//     A3 = U8 = centre of U back-right edge sticker     (p[U][2][1] region)
		//   Path goes A0 -> A1 -> A2 -> A3 (R CW = upward); reversed for R'.
		if($side == 'R'){
			$sticker_centre = function($face, $r, $c) use (&$p){
				$q00 = $p[$face][$r  ][$c  ];
				$q10 = $p[$face][$r+1][$c  ];
				$q11 = $p[$face][$r+1][$c+1];
				$q01 = $p[$face][$r  ][$c+1];
				return array(
					($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
					($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
				);
			};

			$A0 = $sticker_centre($F, $d - 1, $d - 1);   // F9
			$A1 = $sticker_centre($F, $d - 1, 0);        // F7
			$A2 = $sticker_centre($U, $d - 1, $d - 1);   // U9
			$A3 = $sticker_centre($U, $d - 1, 1);        // U8

			$anchors = array($A0, $A1, $A2, $A3);
			if($ccw){
				$anchors = array_reverse($anchors);
			}

			$u_BL = $p[$U][0][0];
			$u_FR = $p[$U][$d][$d];
			$diag_u = sqrt(
				($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
				($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
			);
			$sticker_w = $diag_u / $d / 1.414;
			$thick    = $sticker_w * 0.55;
			$half_t   = $thick / 2;
			$head_w   = $thick * 1.6;
			$head_len = $thick * 1.8;

			$alpha = 0.5;
			$cr_points = array();
			$cr_points[] = $anchors[0];
			foreach($anchors as $a) $cr_points[] = $a;
			$cr_points[] = $anchors[count($anchors)-1];

			$N_per_seg = 24;
			$samples  = array();
			$tangents = array();
			$tj = function($ti, $pi, $pj) use ($alpha){
				$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
				$dist = sqrt($dx*$dx + $dy*$dy);
				if($dist < 1e-9) $dist = 1e-9;
				return $ti + pow($dist, $alpha);
			};
			for($i = 0; $i < count($anchors) - 1; $i++){
				$P0 = $cr_points[$i];
				$P1 = $cr_points[$i+1];
				$P2 = $cr_points[$i+2];
				$P3 = $cr_points[$i+3];
				$t0 = 0.0;
				$t1 = $tj($t0, $P0, $P1);
				$t2 = $tj($t1, $P1, $P2);
				$t3 = $tj($t2, $P2, $P3);
				$is_last = ($i == count($anchors) - 2);
				$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
				for($k = 0; $k <= $last_k; $k++){
					$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
					$A1c = array(
						($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
						($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
					);
					$A2c = array(
						($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
						($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
					);
					$A3c = array(
						($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
						($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
					);
					$B1c = array(
						($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
						($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
					);
					$B2c = array(
						($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
						($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
					);
					$C  = array(
						($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
						($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
					);
					$samples[] = $C;
					$eps = ($t2 - $t1) * 1e-3;
					$tp  = max($t1, min($t2, $t + $eps));
					$A1d = array(
						($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
						($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
					);
					$A2d = array(
						($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
						($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
					);
					$A3d = array(
						($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
						($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
					);
					$B1d = array(
						($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
						($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
					);
					$B2d = array(
						($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
						($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
					);
					$Cd  = array(
						($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
						($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
					);
					$tx = $Cd[0] - $C[0];
					$ty = $Cd[1] - $C[1];
					$tl = sqrt($tx*$tx + $ty*$ty);
					if($tl < 1e-9) $tl = 1;
					$tangents[] = array($tx/$tl, $ty/$tl);
				}
			}
			$N = count($samples) - 1;
			$p_tip = $samples[$N];

			$shaft_end_idx = $N;
			for($k = $N; $k >= 0; $k--){
				$dx = $p_tip[0] - $samples[$k][0];
				$dy = $p_tip[1] - $samples[$k][1];
				if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
			}
			$tip_back     = $samples[$shaft_end_idx];
			$tip_back_tan = $tangents[$shaft_end_idx];

			$left_pts  = array();
			$right_pts = array();
			for($k = 0; $k <= $shaft_end_idx; $k++){
				$nx = -$tangents[$k][1];
				$ny =  $tangents[$k][0];
				$bx = $samples[$k][0];
				$by = $samples[$k][1];
				$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
				$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
			}
			$tn_x = -$tip_back_tan[1];
			$tn_y =  $tip_back_tan[0];
			$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
			$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

			$smooth = function($pts, $reverse = false){
				$n = count($pts);
				if($reverse) $pts = array_reverse($pts);
				$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
				for($i = 1; $i < $n - 1; $i++){
					$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
					$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
					$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
				}
				$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
				return $s;
			};

			$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
			$path_d .= $smooth($left_pts);
			$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
				$base_l[0], $base_l[1],
				$p_tip[0],  $p_tip[1],
				$base_r[0], $base_r[1]);
			$path_d .= " " . $smooth($right_pts, true);
			$path_d .= " Z";

			$stroke_w = $thick * 0.30;
			$gloss_w  = $thick * 0.18;

			$svg  = "\t\t<!-- R glass curved arrow F9 -> F7 -> U9 -> U8 -->\n";
			$svg .= "\t\t<path d=\"{$path_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

			$g_in = max(2, intval($N * 0.08));
			$gs = $g_in;
			$ge = max($gs, $shaft_end_idx - $g_in);
			if($ge > $gs){
				$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
				for($k = $gs+1; $k <= $ge; $k++){
					$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
				}
				$svg .= "\t\t<path d=\"{$g_d}\"\n";
				$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
			}

			return $svg;
		}

		// Legacy R variant: straight F-right-column arrow logic (no longer dispatched).
		// F face corners
		$fc00 = $p[$F][0][0];
		$fc10 = $p[$F][$d][0];
		$fc11 = $p[$F][$d][$d];
		$fc01 = $p[$F][0][$d];

		// Identify which face-u boundary of F corresponds to "left" vs "right" in screen.
		// Compute midpoints of the two u-boundary edges.
		$mid_u0 = array(($fc00[0]+$fc01[0])/2, ($fc00[1]+$fc01[1])/2); // u=0 edge midpoint
		$mid_u1 = array(($fc10[0]+$fc11[0])/2, ($fc10[1]+$fc11[1])/2); // u=d edge midpoint
		// "Left" edge of F = smaller screen X; "right" = greater screen X
		if($mid_u0[0] < $mid_u1[0]){
			$f_left_edge_u0_pt = $fc00; $f_left_edge_u1_pt = $fc01;  // u=0 edge points
			$f_right_edge_u0_pt = $fc10; $f_right_edge_u1_pt = $fc11; // u=d edge points
			$u_left = 0; $u_right = $d;
		} else {
			$f_left_edge_u0_pt = $fc10; $f_left_edge_u1_pt = $fc11;
			$f_right_edge_u0_pt = $fc00; $f_right_edge_u1_pt = $fc01;
			$u_left = $d; $u_right = 0;
		}

		// Pick the column to draw on. The arrow runs along the column from top to bottom
		// of the F face. The column lies between u=u_target and u=u_target_inner
		// (a one-sticker-wide band along the chosen edge).
		if($side == 'L'){
			$u_outer = $u_left;
			$u_inner = $u_left + ($u_right - $u_left) * (1.0 / $d); // one sticker inward
		} else { // R
			$u_outer = $u_right;
			$u_inner = $u_right + ($u_left - $u_right) * (1.0 / $d); // one sticker inward
		}

		// Centerline u position (midway of the column)
		$u_center = ($u_outer + $u_inner) / 2;

		// Bilinear map on the F face
		$bilinear = function($u, $v) use ($fc00, $fc10, $fc11, $fc01, $d){
			$su = $u / $d;  $sv = $v / $d;
			$x = (1-$su)*(1-$sv)*$fc00[0] + $su*(1-$sv)*$fc10[0] + $su*$sv*$fc11[0] + (1-$su)*$sv*$fc01[0];
			$y = (1-$su)*(1-$sv)*$fc00[1] + $su*(1-$sv)*$fc10[1] + $su*$sv*$fc11[1] + (1-$su)*$sv*$fc01[1];
			return array($x, $y);
		};

		// Identify which v direction is "top" (smaller screen Y) of F.
		$probe_v0 = $bilinear($u_center, 0);
		$probe_v1 = $bilinear($u_center, $d);
		$v_top = ($probe_v0[1] < $probe_v1[1]) ? 0 : $d;
		$v_bot = ($v_top == 0) ? $d : 0;

		// Endpoints along the column, with small inset from face edge.
		$inset_v = 0.12;  // fraction of d
		$v_a = $v_top + ($v_bot - $v_top) * $inset_v;          // near top
		$v_b = $v_top + ($v_bot - $v_top) * (1 - $inset_v);    // near bottom

		// Determine direction: which v is the TAIL (start) and which is the TIP (end)?
		// L  CW : down  → tail=top, tip=bot
		// L' CCW: up    → tail=bot, tip=top
		// R  CW : up    → tail=bot, tip=top
		// R' CCW: down  → tail=top, tip=bot
		if($side == 'L'){
			$dir_down = !$ccw;  // L: CW=down; CCW=up
		} else {
			$dir_down = $ccw;   // R: CW=up; CCW=down
		}
		if($dir_down){
			$v_tail = $v_a; $v_tip = $v_b;
		} else {
			$v_tail = $v_b; $v_tip = $v_a;
		}

		// Compute screen-space endpoints
		$p_tail = $bilinear($u_center, $v_tail);
		$p_tip  = $bilinear($u_center, $v_tip);

		// Arrow direction (screen)
		$adx = $p_tip[0] - $p_tail[0];
		$ady = $p_tip[1] - $p_tail[1];
		$alen = sqrt($adx*$adx + $ady*$ady);
		if($alen < 1e-9){ return ''; }
		$aux = $adx / $alen; $auy = $ady / $alen;
		// Perpendicular
		$apx = -$auy; $apy = $aux;

		// Sticker-width scale: project one sticker width through bilinear at center
		$p_col_outer = $bilinear($u_outer, ($v_tail+$v_tip)/2);
		$p_col_inner = $bilinear($u_inner, ($v_tail+$v_tip)/2);
		$sticker_w = sqrt(
			($p_col_outer[0]-$p_col_inner[0])*($p_col_outer[0]-$p_col_inner[0])
			+ ($p_col_outer[1]-$p_col_inner[1])*($p_col_outer[1]-$p_col_inner[1])
		);

		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = min($thick * 1.8, $alen * 0.4);

		// Tip-back (where the shaft meets the arrowhead base)
		$tip_back = array($p_tip[0] - $aux*$head_len, $p_tip[1] - $auy*$head_len);

		// Build polygon: tail-left, tip-back-left, base-left, tip, base-right, tip-back-right, tail-right
		$tail_l = array($p_tail[0] - $apx*$half_t, $p_tail[1] - $apy*$half_t);
		$tail_r = array($p_tail[0] + $apx*$half_t, $p_tail[1] + $apy*$half_t);
		$tb_l   = array($tip_back[0] - $apx*$half_t, $tip_back[1] - $apy*$half_t);
		$tb_r   = array($tip_back[0] + $apx*$half_t, $tip_back[1] + $apy*$half_t);
		$base_l = array($tip_back[0] - $apx*$head_w, $tip_back[1] - $apy*$head_w);
		$base_r = array($tip_back[0] + $apx*$head_w, $tip_back[1] + $apy*$head_w);

		$path_d = sprintf("M %.3f,%.3f L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f Z",
			$tail_l[0], $tail_l[1],
			$tb_l[0],   $tb_l[1],
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1],
			$tb_r[0],   $tb_r[1],
			$tail_r[0], $tail_r[1]
		);

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- {$side} column glass arrow on F face -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		// White gloss highlight: a line along the centerline shrunk inward
		$gloss_inset = $thick * 0.18;
		$g_tail = array($p_tail[0] + $aux*$gloss_inset, $p_tail[1] + $auy*$gloss_inset);
		$g_tip  = array($tip_back[0] - $aux*$gloss_inset, $tip_back[1] - $auy*$gloss_inset);
		$svg .= sprintf("\t\t<path d=\"M %.3f,%.3f L %.3f,%.3f\"\n",
			$g_tail[0], $g_tail[1], $g_tip[0], $g_tip[1]);
		$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";

		return $svg;
	}

	/**
	 * U-move arrow: glass-style wrap arrow analogous to D, but on the upper part of the cube.
	 * Spans R-face top row + F-face top row, wrapping around the U/F/R vertical corner.
	 * Tail at far back-right corner (top of R back column);
	 * tip at the middle of F's top edge (middle of front face top row).
	 */
	function gen_glass_u_wrap_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		// 4-anchor smooth curved glass arrow for U move.
		//   Anchors (sticker centres):
		//     A0 = R7 = centre of R top-front sticker  (p[R][2][0] region)
		//     A1 = R1 = centre of R top-back sticker   (p[R][0][0] region)
		//     A2 = F7 = centre of F top-right sticker  (p[F][2][0] region)
		//     A3 = F4 = centre of F top-middle sticker (p[F][1][0] region)
		//   Path goes A0 -> A1 -> A2 -> A3 (U CW); reversed for U'.
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($R, $d - 1, 0);    // R7
		$A1 = $sticker_centre($R, 0, 0);         // R1
		$A2 = $sticker_centre($F, $d - 1, 0);    // F7
		$A3 = $sticker_centre($F, 1, 0);         // F4

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		// Sticker width estimate (using U-face diagonal)
		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		// Centripetal Catmull-Rom sampling through anchors
		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- U glass curved arrow R7 -> R1 -> F7 -> F4 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}


	/**
	 * B-move arrow: a glass-style arrow that wraps from the back row of U
	 * around the U-R back edge and continues along the back column of R.
	 * Mirrors the structure of gen_glass_d_wrap_arrow but for the upper/back area.
	 */
	function gen_glass_b_wrap_arrow($ccw, $hl){
		global $p, $dim, $U, $R;

		$d = $dim;
		// 4-anchor smooth curved glass arrow for B move.
		//   Anchors (sticker centres):
		//     A0 = R9 = centre of R bottom-front sticker (p[R][d-1][d-1])
		//     A1 = R7 = centre of R top-front sticker    (p[R][d-1][0])
		//     A2 = U7 = centre of U back-right corner    (p[U][d-1][0])
		//     A3 = U4 = centre of U back-mid sticker     (p[U][1][0])
		//   Wait — re-check: U numbering row-major as displayed:
		//     U1=(0,0)=back top, U2=(0,1), U3=(0,2)=front-left,
		//     U4=(1,0), U5=(1,1)=center, U6=(1,2),
		//     U7=(2,0)=back-right, U8=(2,1), U9=(2,2)=front-right.
		//   So U7 = sticker_centre(U, 2, 0); U4 = sticker_centre(U, 1, 0).
		//   R numbering row-major:
		//     R1=(0,0)=top-back, R2=(0,1)=mid-back, R3=(0,2)=bot-back,
		//     R4=(1,0)=top-mid, R5=(1,1)=center, R6=(1,2)=bot-mid,
		//     R7=(2,0)=top-front, R8=(2,1)=mid-front, R9=(2,2)=bot-front.
		//   So R9 = sticker_centre(R, 2, 2); R7 = sticker_centre(R, 2, 0).
		//   Path goes A0 -> A1 -> A2 -> A3 (B CW); reversed for B'.
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($R, $d - 1, $d - 1);   // R9
		$A1 = $sticker_centre($R, $d - 1, 0);        // R7
		$A2 = $sticker_centre($U, $d - 1, 0);        // U7
		$A3 = $sticker_centre($U, 1, 0);             // U4

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		// Sticker width estimate (using U-face diagonal — matches U/L/R/D thickness)
		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		// Centripetal Catmull-Rom sampling through anchors
		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- B glass curved arrow R9 -> R7 -> U7 -> U4 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	function gen_glass_arc_arrow_u($ccw, $hl){
		return gen_glass_arc_arrow_face('U', $ccw, $hl);
	}

	/**
	 * Half-circle glass arrow projected onto a cube face.
	 * Works for U, D (and could be extended to F/B/L/R but those have other styles now).
	 */
	function gen_glass_arc_arrow_face($face_name, $ccw, $hl){
		global $p, $dim, $U, $D, $F, $B, $L, $R;

		$face_map = array('U'=>$U, 'D'=>$D, 'F'=>$F, 'B'=>$B, 'L'=>$L, 'R'=>$R);
		$face_id = isset($face_map[$face_name]) ? $face_map[$face_name] : $U;
		// Face projected corners (screen 2D)
		$c00 = $p[$face_id][0][0];
		$c10 = $p[$face_id][$dim][0];
		$c11 = $p[$face_id][$dim][$dim];
		$c01 = $p[$face_id][0][$dim];

		// Bilinear map: (u,v) in [0,dim] x [0,dim] -> screen point
		$d = $dim;
		$bilinear = function($u, $v) use ($c00, $c10, $c11, $c01, $d){
			$su = $u / $d;  $sv = $v / $d;
			$x = (1-$su)*(1-$sv)*$c00[0] + $su*(1-$sv)*$c10[0] + $su*$sv*$c11[0] + (1-$su)*$sv*$c01[0];
			$y = (1-$su)*(1-$sv)*$c00[1] + $su*(1-$sv)*$c10[1] + $su*$sv*$c11[1] + (1-$su)*$sv*$c01[1];
			return array($x, $y);
		};

		// Half-circle in face-local space, centered on face center
		$cu = $d / 2;  $cv = $d / 2;
		// For D: shift the arc center toward the "front" edge of the D face (screen-bottom),
		// so the arrow sits on the lower/front row of D rather than the middle.
		if($face_name == 'D'){
			// Pick the v direction whose edge midpoint has greater screen-Y (lower on screen = front)
			$mid_v0 = array(($c00[0]+$c10[0])/2, ($c00[1]+$c10[1])/2); // edge at v=0
			$mid_v1 = array(($c01[0]+$c11[0])/2, ($c01[1]+$c11[1])/2); // edge at v=dim
			if($mid_v1[1] > $mid_v0[1]){
				$cv = $d * 1.05;
			} else {
				$cv = $d * -0.05;
			}
			// Shift along u toward the "right" edge (greater screen-X)
			$mid_u0 = array(($c00[0]+$c01[0])/2, ($c00[1]+$c01[1])/2); // edge at u=0
			$mid_u1 = array(($c10[0]+$c11[0])/2, ($c10[1]+$c11[1])/2); // edge at u=dim
			if($mid_u1[0] > $mid_u0[0]){
				$cu = $d * 0.62;
			} else {
				$cu = $d * 0.38;
			}
		}
		// For L/R: shift the arc center to lie just OUTSIDE the front-vertical edge of
		// the face (the edge closest to F), so the arc (bulging inward) covers the
		// visible portion of the side face.
		// $lr_apex_mid records the face-angle whose direction points INTO the face from
		// the chosen $cu (i.e. away from the front edge). It's set together with $cu so
		// the sweep code below can use it.
		$lr_apex_mid = null;
		if($face_name == 'L' || $face_name == 'R'){
			// Which u-edge of the face is the "front" one (closest to F = lying closer
			// to the F face seam in screen space)?
			//   R: F seam is on the LEFT of R (smaller screen X)
			//   L: F seam is on the RIGHT of L (greater screen X)
			$mid_u0 = array(($c00[0]+$c01[0])/2, ($c00[1]+$c01[1])/2); // edge u=0
			$mid_u1 = array(($c10[0]+$c11[0])/2, ($c10[1]+$c11[1])/2); // edge u=dim
			if($face_name == 'R'){
				// front edge = whichever u-edge has SMALLER screen X
				$u0_is_front = ($mid_u0[0] < $mid_u1[0]);
			} else { // L
				// front edge = whichever u-edge has GREATER screen X
				$u0_is_front = ($mid_u0[0] > $mid_u1[0]);
			}
			if($u0_is_front){
				// front edge at u=0 → put center just outside at u=-0.05d
				// apex direction is +u (cos(0)=+1) so apex_mid = 0
				$cu = $d * -0.05;
				$lr_apex_mid = 0.0;
			} else {
				// front edge at u=d → put center just outside at u=1.05d
				// apex direction is -u (cos(π)=-1) so apex_mid = π
				$cu = $d * 1.05;
				$lr_apex_mid = M_PI;
			}
			// Vertical centering: midway is fine for a tall arc spanning top-bottom.
			$cv = $d * 0.5;
		}
		$radius_face   = $d * 0.32;   // ~ medium size
		// For D the arc is shifted toward the front; use a slightly smaller radius
		// so it sits cleanly on the front row.
		if($face_name == 'D'){
			$radius_face = $d * 0.50;
		}
		// For L/R: larger radius so the arc spans the full visible height of the face.
		if($face_name == 'L' || $face_name == 'R'){
			$radius_face = $d * 0.55;
		}
		$thick_face    = $d * 0.10;   // band thickness in face coords
		$head_w_face   = $d * 0.16;   // arrowhead half-width
		$head_len_face = $d * 0.18;   // arrowhead length (along arc)

		$r_out = $radius_face + $thick_face / 2;
		$r_in  = $radius_face - $thick_face / 2;

		// Sweep: half-circle from angle 0 to angle π (180°)
		// CW (default): start at right (a=0), sweep counterclockwise in face coords (a → π) over the "top" (-v direction)
		// In face coords let's say angle 0 = +u axis, angle increases CCW (toward -v which is "front" of U)
		// But we want it to look CW when viewed from above (the standard U convention).
		// Using face coord with v pointing "back" of cube and u pointing "right":
		//   angle 0 = +u (right), π/2 = -v (front), π = -u (left), -π/2 = +v (back)
		// CW move arrow: tail at left (π), going through back (-π/2 = 3π/2), ending tip at right (0 or 2π)
		// CCW: opposite
		// For D face, CW visually = opposite sweep direction in the same UV grid
		// (because we're effectively "looking through" from above)
		$effective_ccw = ($face_name == 'D') ? !$ccw : $ccw;

		// Sweep angle: 180° default (half-circle); reduced for D to make the arc longer and flatter
		$sweep = M_PI;
		if($face_name == 'D'){ $sweep = M_PI * 0.55; }  // ~100°
		$mid = 1.5 * M_PI;  // arc apex at "back" of face (smaller v / -y in screen)

		// For L/R, the arc center is at u≈±0.05*d (front edge of the side face).
		// The arc apex points INTO the face along ±u, and endpoints are near top/bottom (±v).
		if($face_name == 'R' || $face_name == 'L'){
			$sweep = M_PI * 0.85; // a bit less than 180° so endpoints sit inside the face
			$mid = ($lr_apex_mid !== null) ? $lr_apex_mid : 0.0;
		}
		// CW (viewed from outside the face): tail on +u side going through -v (back/top of screen)
		// to tip on -u side would be CCW visually. We want CW = tail at -u, through -v, tip at +u.
		if(!$effective_ccw){
			$a_start = $mid - $sweep / 2;  // tail (left)
			$a_end   = $mid + $sweep / 2;  // tip  (right)
		} else {
			$a_start = $mid + $sweep / 2;
			$a_end   = $mid - $sweep / 2;
		}

		// Reserve angular space for the arrowhead
		$head_ang = $head_len_face / $radius_face;
		$dir_sign = ($a_end > $a_start) ? 1 : -1;
		$a_head_base = $a_end - $dir_sign * $head_ang;

		// Helper to get face-coord point on a circle of given radius at angle a
		$on_arc = function($a, $r) use ($cu, $cv){
			return array($cu + cos($a) * $r, $cv + sin($a) * $r);
		};

		// Compute screen-space tangent at a face-space arc point using the bilinear Jacobian.
		// At face point (u,v), arc tangent in face coords is (-sin a, cos a) * radius * dir.
		// Map this through the bilinear partial derivatives to get the screen tangent.
		$bilinear_jacobian = function($u, $v) use ($c00, $c10, $c11, $c01, $d){
			$su = $u / $d;  $sv = $v / $d;
			// dP/du = (1-sv)*(c10-c00) + sv*(c11-c01),  scaled by 1/d
			$dPdu_x = ((1-$sv)*($c10[0]-$c00[0]) + $sv*($c11[0]-$c01[0])) / $d;
			$dPdu_y = ((1-$sv)*($c10[1]-$c00[1]) + $sv*($c11[1]-$c01[1])) / $d;
			$dPdv_x = ((1-$su)*($c01[0]-$c00[0]) + $su*($c11[0]-$c10[0])) / $d;
			$dPdv_y = ((1-$su)*($c01[1]-$c00[1]) + $su*($c11[1]-$c10[1])) / $d;
			return array($dPdu_x, $dPdu_y, $dPdv_x, $dPdv_y);
		};
		// Returns screen-space tangent vector for a circle parametrized at angle a, radius r
		$arc_tangent_screen = function($a, $r) use ($cu, $cv, $bilinear_jacobian){
			$u = $cu + cos($a) * $r;
			$v = $cv + sin($a) * $r;
			// Face-space tangent: derivative w.r.t. a → (-sin a, cos a) * r
			$tu = -sin($a) * $r;
			$tv =  cos($a) * $r;
			$J = $bilinear_jacobian($u, $v);
			// Screen tangent = J * face tangent
			$sx = $J[0] * $tu + $J[2] * $tv;
			$sy = $J[1] * $tu + $J[3] * $tv;
			return array($sx, $sy);
		};

		// Build one arc edge as N cubic beziers (one per angular segment).
		// Each segment uses endpoint positions + tangents for exact tangent matching.
		$build_arc_edge = function($a_from, $a_to, $r, $segments) use ($on_arc, $bilinear, $arc_tangent_screen){
			$path = '';
			$da = ($a_to - $a_from) / $segments;
			// Standard "magic number" for cubic bezier circle approximation per segment:
			// k = (4/3) * tan(da/4)
			$k = (4.0 / 3.0) * tan(abs($da) / 4.0);
			$sign = ($da >= 0) ? 1 : -1;
			$prev_pt = null;
			for($i = 0; $i < $segments; $i++){
				$a0 = $a_from + $i * $da;
				$a1 = $a_from + ($i + 1) * $da;
				$p0_face = $on_arc($a0, $r);
				$p1_face = $on_arc($a1, $r);
				$p0 = $bilinear($p0_face[0], $p0_face[1]);
				$p1 = $bilinear($p1_face[0], $p1_face[1]);
				// Tangents at endpoints (in screen space)
				$t0 = $arc_tangent_screen($a0, $r);
				$t1 = $arc_tangent_screen($a1, $r);
				// Control points: offset endpoints along tangent by k * (segment angular extent / dt) — but since
				// we used (4/3)tan(da/4), control point distance along tangent = k (when tangent is unit-length × radius).
				// Our tangent magnitude = |dP/da|, and we want offset = k * |dP/da| * sign(da)
				// Actually for unit-circle: cp = endpoint + k * tangent_unit * radius * sign
				// Since our tangent already includes the radius scaling, offset = k * tangent * sign
				$cp1x = $p0[0] + $k * $t0[0] * $sign;
				$cp1y = $p0[1] + $k * $t0[1] * $sign;
				$cp2x = $p1[0] - $k * $t1[0] * $sign;
				$cp2y = $p1[1] - $k * $t1[1] * $sign;
				$path .= sprintf(" C %.2f,%.2f %.2f,%.2f %.2f,%.2f", $cp1x, $cp1y, $cp2x, $cp2y, $p1[0], $p1[1]);
			}
			return $path;
		};

		// Use 4 bezier segments per arc edge — gives near-perfect circle approximation
		$arc_segs = 4;

		// Helper: emit the full closed arrow path
		$build_arrow = function($r_out_use, $r_in_use, $head_w_use) use ($build_arc_edge, $bilinear, $on_arc, $a_start, $a_head_base, $a_end, $arc_segs, $radius_face){
			$start_outer = $bilinear($on_arc($a_start, $r_out_use)[0], $on_arc($a_start, $r_out_use)[1]);
			$d  = sprintf("M %.2f,%.2f", $start_outer[0], $start_outer[1]);
			// Outer arc
			$d .= $build_arc_edge($a_start, $a_head_base, $r_out_use, $arc_segs);
			// Arrowhead corners (sharp lines)
			$ah1 = $bilinear($on_arc($a_head_base, $radius_face + $head_w_use)[0], $on_arc($a_head_base, $radius_face + $head_w_use)[1]);
			$tip = $bilinear($on_arc($a_end,       $radius_face)[0],              $on_arc($a_end,       $radius_face)[1]);
			$ah2 = $bilinear($on_arc($a_head_base, $radius_face - $head_w_use)[0], $on_arc($a_head_base, $radius_face - $head_w_use)[1]);
			$d .= sprintf(" L %.2f,%.2f L %.2f,%.2f L %.2f,%.2f", $ah1[0], $ah1[1], $tip[0], $tip[1], $ah2[0], $ah2[1]);
			// Line to inner arc start (at a_head_base, r_in)
			$inner_head = $bilinear($on_arc($a_head_base, $r_in_use)[0], $on_arc($a_head_base, $r_in_use)[1]);
			$d .= sprintf(" L %.2f,%.2f", $inner_head[0], $inner_head[1]);
			// Inner arc back
			$d .= $build_arc_edge($a_head_base, $a_start, $r_in_use, $arc_segs);
			$d .= " Z";
			return $d;
		};

		$path_d = $build_arrow($r_out, $r_in, $head_w_face);

		// Reference size for stroke widths (use a screen length for visual consistency)
		$diag_screen = sqrt(($c11[0]-$c00[0])*($c11[0]-$c00[0]) + ($c11[1]-$c00[1])*($c11[1]-$c00[1]));
		$stroke_w = $diag_screen * 0.012;
		$gloss_w  = $diag_screen * 0.008;

		$svg  = "\t\t<!-- glass arc arrow on U face -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		// Inner gloss: shrink radii inward by a small amount in face space
		$shrink_face = $thick_face * 0.18;
		$gloss_d = $build_arrow($r_out - $shrink_face, $r_in + $shrink_face, $head_w_face - $shrink_face);
		$svg .= "\t\t<path d=\"{$gloss_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linejoin:round\"/>\n";

		return $svg;
	}


	/**
	 * x cube rotation: glass-style curved arrow with 4 anchors.
	 *   F6 (F mid-right edge) -> F4 (F top-middle) -> U6 (U front-middle edge)
	 *     -> U5 (U centre).
	 * Sticker indices follow row-major p[face][r][c] numbering used by the
	 * &numbers=1 overlay.
	 */
	function gen_glass_x_rotation_arrow($ccw, $hl){
		global $p, $dim, $F, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		// Anchors (with d=3):
		//   F5 = (1, 1), F4 = (1, 0)
		//   U6 = (1, 2), U5 = (1, 1)
		$A0 = $sticker_centre($F, 1, 1);        // F5
		$A1 = $sticker_centre($F, 1, 0);        // F4
		$A2 = $sticker_centre($U, 1, $d - 1);   // U6
		$A3 = $sticker_centre($U, 1, 1);        // U5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		// Sticker width estimate (U-face diagonal, matches other moves)
		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- x rotation glass curved arrow F5 -> F4 -> U6 -> U5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	/**
	 * y cube rotation: glass-style curved arrow with 4 anchors.
	 *   R5 (R centre) -> R2 (R mid-back) -> F8 (F mid-bottom) -> F5 (F centre).
	 */
	function gen_glass_y_rotation_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($R, 1, 1);   // R5
		$A1 = $sticker_centre($R, 0, 1);   // R2
		$A2 = $sticker_centre($F, $d - 1, 1);   // F8
		$A3 = $sticker_centre($F, 1, 1);   // F5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- y rotation glass curved arrow R5 -> R2 -> F8 -> F5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	/**
	 * z cube rotation: glass-style curved arrow with 4 anchors.
	 *   U5 -> U8 -> R4 -> R5.
	 */
	function gen_glass_z_rotation_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($U, 1, 1);        // U5
		$A1 = $sticker_centre($U, $d - 1, 1);   // U8
		$A2 = $sticker_centre($R, 1, 0);        // R4
		$A3 = $sticker_centre($R, 1, 1);        // R5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- z rotation glass curved arrow U5 -> U8 -> R4 -> R5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	/**
	 * M slice move: glass-style curved arrow with 4 anchors.
	 *   U4 -> U6 -> F4 -> F5.
	 */
	function gen_glass_m_slice_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($U, 1, 0);        // U4
		$A1 = $sticker_centre($U, 1, $d - 1);   // U6
		$A2 = $sticker_centre($F, 1, 0);        // F4
		$A3 = $sticker_centre($F, 1, 1);        // F5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- M slice arrow U4 -> U6 -> F4 -> F5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	/**
	 * E slice move: glass-style curved arrow with 4 anchors.
	 *   F2 -> F8 -> R2 -> R5.
	 */
	function gen_glass_e_slice_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($F, 0, 1);        // F2
		$A1 = $sticker_centre($F, $d - 1, 1);   // F8
		$A2 = $sticker_centre($R, 0, 1);        // R2
		$A3 = $sticker_centre($R, 1, 1);        // R5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- E slice arrow F2 -> F8 -> R2 -> R5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}

	/**
	 * S slice move: glass-style curved arrow with 4 anchors.
	 *   U2 -> U8 -> R4 -> R5.
	 */
	function gen_glass_s_slice_arrow($ccw, $hl){
		global $p, $dim, $F, $R, $U;

		$d = $dim;
		$sticker_centre = function($face, $r, $c) use (&$p){
			$q00 = $p[$face][$r  ][$c  ];
			$q10 = $p[$face][$r+1][$c  ];
			$q11 = $p[$face][$r+1][$c+1];
			$q01 = $p[$face][$r  ][$c+1];
			return array(
				($q00[0] + $q10[0] + $q11[0] + $q01[0]) / 4,
				($q00[1] + $q10[1] + $q11[1] + $q01[1]) / 4
			);
		};

		$A0 = $sticker_centre($U, 0, 1);        // U2
		$A1 = $sticker_centre($U, $d - 1, 1);   // U8
		$A2 = $sticker_centre($R, 1, 0);        // R4
		$A3 = $sticker_centre($R, 1, 1);        // R5

		$anchors = array($A0, $A1, $A2, $A3);
		if($ccw){
			$anchors = array_reverse($anchors);
		}

		$u_BL = $p[$U][0][0];
		$u_FR = $p[$U][$d][$d];
		$diag_u = sqrt(
			($u_BL[0]-$u_FR[0])*($u_BL[0]-$u_FR[0]) +
			($u_BL[1]-$u_FR[1])*($u_BL[1]-$u_FR[1])
		);
		$sticker_w = $diag_u / $d / 1.414;
		$thick    = $sticker_w * 0.55;
		$half_t   = $thick / 2;
		$head_w   = $thick * 1.6;
		$head_len = $thick * 1.8;

		$alpha = 0.5;
		$cr_points = array();
		$cr_points[] = $anchors[0];
		foreach($anchors as $a) $cr_points[] = $a;
		$cr_points[] = $anchors[count($anchors)-1];

		$N_per_seg = 24;
		$samples  = array();
		$tangents = array();
		$tj = function($ti, $pi, $pj) use ($alpha){
			$dx = $pj[0]-$pi[0]; $dy = $pj[1]-$pi[1];
			$dist = sqrt($dx*$dx + $dy*$dy);
			if($dist < 1e-9) $dist = 1e-9;
			return $ti + pow($dist, $alpha);
		};
		for($i = 0; $i < count($anchors) - 1; $i++){
			$P0 = $cr_points[$i];
			$P1 = $cr_points[$i+1];
			$P2 = $cr_points[$i+2];
			$P3 = $cr_points[$i+3];
			$t0 = 0.0;
			$t1 = $tj($t0, $P0, $P1);
			$t2 = $tj($t1, $P1, $P2);
			$t3 = $tj($t2, $P2, $P3);
			$is_last = ($i == count($anchors) - 2);
			$last_k = $is_last ? $N_per_seg : $N_per_seg - 1;
			for($k = 0; $k <= $last_k; $k++){
				$t = $t1 + ($t2 - $t1) * ($k / $N_per_seg);
				$A1c = array(
					($t1-$t)/($t1-$t0)*$P0[0] + ($t-$t0)/($t1-$t0)*$P1[0],
					($t1-$t)/($t1-$t0)*$P0[1] + ($t-$t0)/($t1-$t0)*$P1[1]
				);
				$A2c = array(
					($t2-$t)/($t2-$t1)*$P1[0] + ($t-$t1)/($t2-$t1)*$P2[0],
					($t2-$t)/($t2-$t1)*$P1[1] + ($t-$t1)/($t2-$t1)*$P2[1]
				);
				$A3c = array(
					($t3-$t)/($t3-$t2)*$P2[0] + ($t-$t2)/($t3-$t2)*$P3[0],
					($t3-$t)/($t3-$t2)*$P2[1] + ($t-$t2)/($t3-$t2)*$P3[1]
				);
				$B1c = array(
					($t2-$t)/($t2-$t0)*$A1c[0] + ($t-$t0)/($t2-$t0)*$A2c[0],
					($t2-$t)/($t2-$t0)*$A1c[1] + ($t-$t0)/($t2-$t0)*$A2c[1]
				);
				$B2c = array(
					($t3-$t)/($t3-$t1)*$A2c[0] + ($t-$t1)/($t3-$t1)*$A3c[0],
					($t3-$t)/($t3-$t1)*$A2c[1] + ($t-$t1)/($t3-$t1)*$A3c[1]
				);
				$C  = array(
					($t2-$t)/($t2-$t1)*$B1c[0] + ($t-$t1)/($t2-$t1)*$B2c[0],
					($t2-$t)/($t2-$t1)*$B1c[1] + ($t-$t1)/($t2-$t1)*$B2c[1]
				);
				$samples[] = $C;
				$eps = ($t2 - $t1) * 1e-3;
				$tp  = max($t1, min($t2, $t + $eps));
				$A1d = array(
					($t1-$tp)/($t1-$t0)*$P0[0] + ($tp-$t0)/($t1-$t0)*$P1[0],
					($t1-$tp)/($t1-$t0)*$P0[1] + ($tp-$t0)/($t1-$t0)*$P1[1]
				);
				$A2d = array(
					($t2-$tp)/($t2-$t1)*$P1[0] + ($tp-$t1)/($t2-$t1)*$P2[0],
					($t2-$tp)/($t2-$t1)*$P1[1] + ($tp-$t1)/($t2-$t1)*$P2[1]
				);
				$A3d = array(
					($t3-$tp)/($t3-$t2)*$P2[0] + ($tp-$t2)/($t3-$t2)*$P3[0],
					($t3-$tp)/($t3-$t2)*$P2[1] + ($tp-$t2)/($t3-$t2)*$P3[1]
				);
				$B1d = array(
					($t2-$tp)/($t2-$t0)*$A1d[0] + ($tp-$t0)/($t2-$t0)*$A2d[0],
					($t2-$tp)/($t2-$t0)*$A1d[1] + ($tp-$t0)/($t2-$t0)*$A2d[1]
				);
				$B2d = array(
					($t3-$tp)/($t3-$t1)*$A2d[0] + ($tp-$t1)/($t3-$t1)*$A3d[0],
					($t3-$tp)/($t3-$t1)*$A2d[1] + ($tp-$t1)/($t3-$t1)*$A3d[1]
				);
				$Cd  = array(
					($t2-$tp)/($t2-$t1)*$B1d[0] + ($tp-$t1)/($t2-$t1)*$B2d[0],
					($t2-$tp)/($t2-$t1)*$B1d[1] + ($tp-$t1)/($t2-$t1)*$B2d[1]
				);
				$tx = $Cd[0] - $C[0];
				$ty = $Cd[1] - $C[1];
				$tl = sqrt($tx*$tx + $ty*$ty);
				if($tl < 1e-9) $tl = 1;
				$tangents[] = array($tx/$tl, $ty/$tl);
			}
		}
		$N = count($samples) - 1;
		$p_tip = $samples[$N];

		$shaft_end_idx = $N;
		for($k = $N; $k >= 0; $k--){
			$dx = $p_tip[0] - $samples[$k][0];
			$dy = $p_tip[1] - $samples[$k][1];
			if(sqrt($dx*$dx + $dy*$dy) >= $head_len){ $shaft_end_idx = $k; break; }
		}
		$tip_back     = $samples[$shaft_end_idx];
		$tip_back_tan = $tangents[$shaft_end_idx];

		$left_pts  = array();
		$right_pts = array();
		for($k = 0; $k <= $shaft_end_idx; $k++){
			$nx = -$tangents[$k][1];
			$ny =  $tangents[$k][0];
			$bx = $samples[$k][0];
			$by = $samples[$k][1];
			$left_pts[]  = array($bx + $nx*$half_t, $by + $ny*$half_t);
			$right_pts[] = array($bx - $nx*$half_t, $by - $ny*$half_t);
		}
		$tn_x = -$tip_back_tan[1];
		$tn_y =  $tip_back_tan[0];
		$base_l = array($tip_back[0] + $tn_x*$head_w, $tip_back[1] + $tn_y*$head_w);
		$base_r = array($tip_back[0] - $tn_x*$head_w, $tip_back[1] - $tn_y*$head_w);

		$smooth = function($pts, $reverse = false){
			$n = count($pts);
			if($reverse) $pts = array_reverse($pts);
			$s = sprintf("L %.3f,%.3f", $pts[0][0], $pts[0][1]);
			for($i = 1; $i < $n - 1; $i++){
				$mx = ($pts[$i][0] + $pts[$i+1][0]) / 2;
				$my = ($pts[$i][1] + $pts[$i+1][1]) / 2;
				$s .= sprintf(" Q %.3f,%.3f %.3f,%.3f", $pts[$i][0], $pts[$i][1], $mx, $my);
			}
			$s .= sprintf(" L %.3f,%.3f", $pts[$n-1][0], $pts[$n-1][1]);
			return $s;
		};

		$path_d  = sprintf("M %.3f,%.3f ", $left_pts[0][0], $left_pts[0][1]);
		$path_d .= $smooth($left_pts);
		$path_d .= sprintf(" L %.3f,%.3f L %.3f,%.3f L %.3f,%.3f",
			$base_l[0], $base_l[1],
			$p_tip[0],  $p_tip[1],
			$base_r[0], $base_r[1]);
		$path_d .= " " . $smooth($right_pts, true);
		$path_d .= " Z";

		$stroke_w = $thick * 0.30;
		$gloss_w  = $thick * 0.18;

		$svg  = "\t\t<!-- S slice arrow U2 -> U8 -> R4 -> R5 -->\n";
		$svg .= "\t\t<path d=\"{$path_d}\"\n";
		$svg .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

		$g_in = max(2, intval($N * 0.08));
		$gs = $g_in;
		$ge = max($gs, $shaft_end_idx - $g_in);
		if($ge > $gs){
			$g_d = sprintf("M %.3f,%.3f", $samples[$gs][0], $samples[$gs][1]);
			for($k = $gs+1; $k <= $ge; $k++){
				$g_d .= sprintf(" L %.3f,%.3f", $samples[$k][0], $samples[$k][1]);
			}
			$svg .= "\t\t<path d=\"{$g_d}\"\n";
			$svg .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linecap:round\"/>\n";
		}

		return $svg;
	}
	function gen_move_arrow($face, $ccw, $col, $double = false, $bulge = 0.5, $hl = false, $kind = ''){
		global $p, $dim, $rv, $U, $R, $F, $D, $L, $B;

		if($col == 't') return '';

		// --- Cube rotation arrows (x / y / z) ---
		if(!$double && $kind === 'x'){
			return gen_glass_x_rotation_arrow($ccw, $hl);
		}
		if(!$double && $kind === 'y'){
			return gen_glass_y_rotation_arrow($ccw, $hl);
		}
		if(!$double && $kind === 'z'){
			return gen_glass_z_rotation_arrow($ccw, $hl);
		}
		if(!$double && $kind === 'M'){
			return gen_glass_m_slice_arrow($ccw, $hl);
		}
		if(!$double && $kind === 'E'){
			return gen_glass_e_slice_arrow($ccw, $hl);
		}
		if(!$double && $kind === 'S'){
			return gen_glass_s_slice_arrow($ccw, $hl);
		}

		// --- Wrapping glass arrow for U move: spans top row of L + top row of F ---
		if(!$double && $face == $U){
			return gen_glass_u_wrap_arrow($ccw, $hl);
		}
		// --- Half-circle glass arrow for F face (projected onto face) ---
		if(!$double && $face == $F){
			return gen_glass_arc_arrow_face('F', $ccw, $hl);
		}
		// --- Wrapping glass arrow for B move: spans back row of U + back column of R ---
		if(!$double && $face == $B){
			return gen_glass_b_wrap_arrow($ccw, $hl);
		}
		// --- Wrapping straight glass arrow for D move: spans bottom row of F + bottom row of R ---
		if(!$double && $face == $D){
			return gen_glass_d_wrap_arrow($ccw, $hl);
		}
		// --- L/R move: vertical glass arrow on F's left or right column ---
		if(!$double && ($face == $L || $face == $R)){
			return gen_glass_lr_column_arrow($face == $L ? 'L' : 'R', $ccw, $hl);
		}
		// --- (Legacy straight on-face arrow, kept for reference but unreached) ---
		if(false && !$double && ($face == $L || $face == $R)){
			// 4 face edges (pairs of corners, in order around the face)
			$corners4 = Array(
				array($p[$face][0][0],       $p[$face][$dim][0]),
				array($p[$face][$dim][0],    $p[$face][$dim][$dim]),
				array($p[$face][$dim][$dim], $p[$face][0][$dim]),
				array($p[$face][0][$dim],    $p[$face][0][0]),
			);

			// Pick the visible outer edge per face:
			//   L → leftmost edge of L face  (min X) — outer left silhouette
			//   R → leftmost edge of R face  (min X) — front-shared edge with F
			$best = 0;
			if($face == $L){
				$best_v = 1e9;
				foreach($corners4 as $i => $e){ $v = ($e[0][0]+$e[1][0])/2; if($v < $best_v){ $best_v=$v; $best=$i; } }
			} else { // R
				$best_v = 1e9;
				foreach($corners4 as $i => $e){ $v = ($e[0][0]+$e[1][0])/2; if($v < $best_v){ $best_v=$v; $best=$i; } }
			}
			$e = $corners4[$best];

			// CW direction along the outer edge:
			//   L (CW) = downward (top→bottom)
			//   R (CW) = upward   (bottom→top)
			$lo = ($e[0][1] < $e[1][1]) ? $e[0] : $e[1]; // top
			$hi = ($e[0][1] < $e[1][1]) ? $e[1] : $e[0]; // bottom
			if($face == $L){ $cw_start = $lo; $cw_end = $hi; }
			else           { $cw_start = $hi; $cw_end = $lo; }

			if(!$ccw){ $sx=$cw_start[0]; $sy=$cw_start[1]; $ex=$cw_end[0];   $ey=$cw_end[1]; }
			else     { $sx=$cw_end[0];   $sy=$cw_end[1];   $ex=$cw_start[0]; $ey=$cw_start[1]; }

			// Shrink endpoints a bit so the arrow doesn't touch the corners
			$dx = $ex - $sx; $dy = $ey - $sy;
			$len = sqrt($dx*$dx + $dy*$dy);
			$shrink = 0.12;
			$sx += $dx * $shrink;  $sy += $dy * $shrink;
			$ex -= $dx * $shrink;  $ey -= $dy * $shrink;

			$tx = ($ex - $sx) / $len;  $ty = ($ey - $sy) / $len;
			$sw = $len * 0.10;
			$head_len = $len * 0.18;
			$head_w   = $len * 0.10;

			// Straight arrow = bezier with control point at midpoint
			$qx = ($sx + $ex) / 2;  $qy = ($sy + $ey) / 2;

			$svg = "\t\t<!-- straight move arrow face=$face -->\n";
			$svg .= gen_move_arrow_bez($sx, $sy, $qx, $qy, $ex, $ey, $sw, $col, $tx, $ty, $head_len, $head_w, $hl);
			return $svg;
		}

		$back_face = ($face == $D || $face == $L || $face == $B);

		// Use each face's own projected corners for all faces.
		$cx = 0; $cy = 0;
		$corners = Array(
			$p[$face][0][0],
			$p[$face][$dim][0],
			$p[$face][$dim][$dim],
			$p[$face][0][$dim],
		);
		foreach($corners as $c){ $cx += $c[0]; $cy += $c[1]; }
		$cx /= 4; $cy /= 4;

		// Outward unit normal: from cube screen-centre toward face centre.
		$nlen = sqrt($cx*$cx + $cy*$cy);
		if($nlen < 0.001){ $nx = 0; $ny = -1; }
		else { $nx = $cx / $nlen;  $ny = $cy / $nlen; }

		// Lateral unit vector (perpendicular to normal, 90° CCW).
		$lx = -$ny;  $ly = $nx;

		// Find the most-outward corner in the outward normal direction.
		$out_vals = array();
		foreach($corners as $i => $c){
			$out_vals[$i] = ($c[0]-$cx)*$nx + ($c[1]-$cy)*$ny;
		}
		arsort($out_vals);
		$keys = array_keys($out_vals);
		$top_idx  = $keys[0];
		$prev_idx = ($top_idx + 3) % 4;
		$next_idx = ($top_idx + 1) % 4;
		$c_top  = $corners[$top_idx];
		$c_prev = $corners[$prev_idx];
		$c_next = $corners[$next_idx];

		// Arc endpoints = midpoints of the two edges adjacent to the peak corner.
		$p_a = array(($c_top[0]+$c_prev[0])/2, ($c_top[1]+$c_prev[1])/2);
		$p_b = array(($c_top[0]+$c_next[0])/2, ($c_top[1]+$c_next[1])/2);

		// Sort: p_a = more negative lateral (left), p_b = more positive lateral (right)
		$lat_a = ($p_a[0]-$cx)*$lx + ($p_a[1]-$cy)*$ly;
		$lat_b = ($p_b[0]-$cx)*$lx + ($p_b[1]-$cy)*$ly;
		if($lat_a > $lat_b){ $tmp=$p_a; $p_a=$p_b; $p_b=$tmp; }

		// Chord length
		$chord = sqrt(($p_b[0]-$p_a[0])*($p_b[0]-$p_a[0]) + ($p_b[1]-$p_a[1])*($p_b[1]-$p_a[1]));

		// Bezier control point: bulge=0.0→0.05×chord (flat), bulge=1.0→0.60×chord (very curved)
		$bulge_push = 0.05 + $bulge * 0.55;
		$qx = $c_top[0] + $nx * $chord * $bulge_push;
		$qy = $c_top[1] + $ny * $chord * $bulge_push;

		// For a quadratic bezier Q ctrl end, the tangent at the end is (end - ctrl).
		// CW: p_a (left) → p_b (right),  CCW: p_b → p_a
		if(!$ccw){
			$sx = $p_a[0]; $sy = $p_a[1];
			$ex = $p_b[0]; $ey = $p_b[1];
		} else {
			$sx = $p_b[0]; $sy = $p_b[1];
			$ex = $p_a[0]; $ey = $p_a[1];
		}

		// Tangent at endpoint = endpoint - control point (quadratic bezier tangent)
		$tx = $ex - $qx;  $ty = $ey - $qy;
		$tlen = sqrt($tx*$tx + $ty*$ty);
		if($tlen > 1e-9){ $tx /= $tlen; $ty /= $tlen; }

		// Stroke width and arrowhead proportional to chord
		$sw       = $chord * 0.09;
		$head_len = $chord * 0.22;
		$head_w   = $chord * 0.11;

		$svg = "\t\t<!-- move arrow face=$face -->\n";

		if($double){
			// Two concentric arcs, both outside the face — inner and outer.
			// Spread the two arcs symmetrically around the bulge push
			$spread = $chord * 0.20;
			$base_push = 0.05 + $bulge * 0.55;
			$qx_in  = $c_top[0] + $nx * ($chord * $base_push - $spread * 0.5);
			$qy_in  = $c_top[1] + $ny * ($chord * $base_push - $spread * 0.5);
			$qx_out = $c_top[0] + $nx * ($chord * $base_push + $spread * 0.5);
			$qy_out = $c_top[1] + $ny * ($chord * $base_push + $spread * 0.5);
			// Tangents for each
			$tx_in = $ex - $qx_in; $ty_in = $ey - $qy_in;
			$tl = sqrt($tx_in*$tx_in+$ty_in*$ty_in);
			if($tl>1e-9){$tx_in/=$tl;$ty_in/=$tl;}
			$tx_out = $ex - $qx_out; $ty_out = $ey - $qy_out;
			$tl = sqrt($tx_out*$tx_out+$ty_out*$ty_out);
			if($tl>1e-9){$tx_out/=$tl;$ty_out/=$tl;}
			// Draw inner arc (no arrowhead), outer arc (with arrowhead)
			$svg .= gen_move_arrow_bez($sx, $sy, $qx_in,  $qy_in,  $ex, $ey, $sw, $col, $tx_in,  $ty_in,  0,          0,         $hl);
			$svg .= gen_move_arrow_bez($sx, $sy, $qx_out, $qy_out, $ex, $ey, $sw, $col, $tx_out, $ty_out, $head_len, $head_w, $hl);
			return $svg;
		}

		$svg .= gen_move_arrow_bez($sx, $sy, $qx, $qy, $ex, $ey, $sw, $col, $tx, $ty, $head_len, $head_w, $hl);

		return $svg;
	}

	/** Helper: draws one bezier arc segment with optional arrowhead and highlight effect */
	function gen_move_arrow_bez($sx, $sy, $qx, $qy, $ex, $ey, $sw, $col, $tx, $ty, $head_len, $head_w, $hl = false){
		$has_head = ($head_len > 0 && $head_w > 0);
		$travel = atan2($ty, $tx);
		$perp   = $travel + M_PI/2;

		// Detect straight arrow: control point at (or very near) the segment midpoint
		$mx = ($sx + $ex) / 2;  $my = ($sy + $ey) / 2;
		$dq = sqrt(($qx-$mx)*($qx-$mx) + ($qy-$my)*($qy-$my));
		$seg = sqrt(($ex-$sx)*($ex-$sx) + ($ey-$sy)*($ey-$sy));
		$is_straight = ($dq < $seg * 0.02);

		$out = '';

		// --- Glass-style straight arrow: single closed polygon with gradient + gloss ---
		if($hl && $is_straight && $has_head){
			static $grad_id = 0;
			$grad_id++;
			$gid = "arrowGlass{$grad_id}";

			$half_sw = $sw / 2;
			// Shaft direction unit vector
			$ux = $tx; $uy = $ty;
			// Perpendicular unit vector
			$px = cos($perp); $py = sin($perp);

			// Arrow polygon points (start at tail-left, go around clockwise):
			//   tail_L  ─────────  shoulder_L
			//                          │
			//                          base_L
			//                          \
			//                           tip
			//                          /
			//                          base_R
			//                          │
			//   tail_R  ─────────  shoulder_R
			$tail_L = array($sx + $px*$half_sw, $sy + $py*$half_sw);
			$tail_R = array($sx - $px*$half_sw, $sy - $py*$half_sw);
			// Shoulder = where shaft meets arrowhead base, just before $ex
			$shoulder_L = array($ex + $px*$half_sw, $ey + $py*$half_sw);
			$shoulder_R = array($ex - $px*$half_sw, $ey - $py*$half_sw);
			// Arrowhead base corners (wider than shaft)
			$base_L = array($ex + $px*$head_w, $ey + $py*$head_w);
			$base_R = array($ex - $px*$head_w, $ey - $py*$head_w);
			// Tip
			$tip = array($ex + $ux*$head_len, $ey + $uy*$head_len);

			$pts = sprintf("%.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f",
				$tail_L[0], $tail_L[1],
				$shoulder_L[0], $shoulder_L[1],
				$base_L[0], $base_L[1],
				$tip[0], $tip[1],
				$base_R[0], $base_R[1],
				$shoulder_R[0], $shoulder_R[1],
				$tail_R[0], $tail_R[1]
			);

			// Gradient direction: perpendicular to shaft (so gloss runs across the arrow width)
			$bbox_min_x = min($tail_L[0],$tail_R[0],$base_L[0],$base_R[0],$tip[0]);
			$bbox_max_x = max($tail_L[0],$tail_R[0],$base_L[0],$base_R[0],$tip[0]);
			$bbox_min_y = min($tail_L[1],$tail_R[1],$base_L[1],$base_R[1],$tip[1]);
			$bbox_max_y = max($tail_L[1],$tail_R[1],$base_L[1],$base_R[1],$tip[1]);
			$gx1 = $bbox_min_x; $gy1 = $bbox_min_y;
			$gx2 = $bbox_max_x; $gy2 = $bbox_max_y;

			$stroke_w = $sw * 0.30;
			$gloss_w  = $sw * 0.18;

			// Solid single-color gray fill (no gradient → no halved appearance)
			$out .= "\t\t<polygon points=\"{$pts}\"\n";
			$out .= "\t\t\tstyle=\"fill:#aaaaaa;stroke:#000000;stroke-width:{$stroke_w};stroke-linejoin:round\"/>\n";

			// Inset gloss highlight: shrunk version of the polygon, white stroke at 50% opacity
			// Offset each point inward by ~ stroke_w toward the polygon centroid
			$cx = ($bbox_min_x + $bbox_max_x) / 2;
			$cy = ($bbox_min_y + $bbox_max_y) / 2;
			$shrink = $sw * 0.18;
			$shrink_pt = function($p) use ($cx, $cy, $shrink) {
				$dx = $cx - $p[0]; $dy = $cy - $p[1];
				$d = sqrt($dx*$dx+$dy*$dy);
				if($d < 1e-9) return $p;
				return array($p[0] + $dx/$d * $shrink, $p[1] + $dy/$d * $shrink);
			};
			$g_tail_L = $shrink_pt($tail_L);
			$g_shoulder_L = $shrink_pt($shoulder_L);
			$g_base_L = $shrink_pt($base_L);
			$g_tip = $shrink_pt($tip);
			$g_base_R = $shrink_pt($base_R);
			$g_shoulder_R = $shrink_pt($shoulder_R);
			$g_tail_R = $shrink_pt($tail_R);
			$gpts = sprintf("%.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f",
				$g_tail_L[0],$g_tail_L[1], $g_shoulder_L[0],$g_shoulder_L[1],
				$g_base_L[0],$g_base_L[1], $g_tip[0],$g_tip[1],
				$g_base_R[0],$g_base_R[1], $g_shoulder_R[0],$g_shoulder_R[1],
				$g_tail_R[0],$g_tail_R[1]
			);
			$out .= "\t\t<polygon points=\"{$gpts}\"\n";
			$out .= "\t\t\tstyle=\"fill:none;stroke:#ffffff;stroke-width:{$gloss_w};stroke-opacity:0.55;stroke-linejoin:round\"/>\n";

			return $out;
		}

		if($hl){
			// --- Highlight effect for curved arrows: dark thick outline + colored fill + sheen ---
			// Parse base color
			$r = hexdec(substr($col, 0, 2));
			$g = hexdec(substr($col, 2, 2));
			$b = hexdec(substr($col, 4, 2));
			// Light overlay color: mix 60% original + 40% white
			$lr = (int)round($r * 0.60 + 255 * 0.40);
			$lg = (int)round($g * 0.60 + 255 * 0.40);
			$lb = (int)round($b * 0.60 + 255 * 0.40);
			$light_col = sprintf('%02x%02x%02x', $lr, $lg, $lb);

			$sw_outline = $sw * 2.0;
			$sw_inner   = $sw * 1.0;
			$head_outline_w = ($sw_outline - $sw_inner) / 2;

			// Pass 1: thick black outline (path)
			$out .= "\t\t<path d=\"M {$sx},{$sy} Q {$qx},{$qy} {$ex},{$ey}\"\n";
			$out .= "\t\t\tstyle=\"fill:none;stroke:#000000;stroke-width:{$sw_outline};stroke-linecap:round;stroke-linejoin:round\"/>\n";

			// Pass 2: colored inner stroke
			$out .= "\t\t<path d=\"M {$sx},{$sy} Q {$qx},{$qy} {$ex},{$ey}\"\n";
			$out .= "\t\t\tstyle=\"fill:none;stroke:#{$col};stroke-width:{$sw_inner};stroke-linecap:round;stroke-linejoin:round\"/>\n";

			// Pass 3: arrowhead with both fill and a black stroke
			if($has_head){
				$tip_x = $ex + $tx * $head_len;
				$tip_y = $ey + $ty * $head_len;
				$b1x = $ex + cos($perp)*$head_w;  $b1y = $ey + sin($perp)*$head_w;
				$b2x = $ex - cos($perp)*$head_w;  $b2y = $ey - sin($perp)*$head_w;
				$out .= "\t\t<polygon points=\"{$tip_x},{$tip_y} {$b1x},{$b1y} {$b2x},{$b2y}\"\n";
				$out .= "\t\t\tstyle=\"fill:#{$col};stroke:#000000;stroke-width:{$head_outline_w};stroke-linejoin:round;stroke-linecap:round\"/>\n";
			}

			// Pass 4: thin light sheen streak on top
			$out .= "\t\t<path d=\"M {$sx},{$sy} Q {$qx},{$qy} {$ex},{$ey}\"\n";
			$out .= "\t\t\tstyle=\"fill:none;stroke:#{$light_col};stroke-width:" . ($sw_inner * 0.35) . ";stroke-linecap:round;stroke-opacity:0.75\"/>\n";
		} else {
			// --- Standard rendering ---
			$out .= "\t\t<path d=\"M {$sx},{$sy} Q {$qx},{$qy} {$ex},{$ey}\"\n";
			$out .= "\t\t\tstyle=\"fill:none;stroke:#{$col};stroke-width:{$sw};stroke-linecap:round;stroke-opacity:1\"/>\n";
			if($has_head){
				$tip_x = $ex + $tx * $head_len;
				$tip_y = $ey + $ty * $head_len;
				$b1x = $ex + cos($perp)*$head_w;  $b1y = $ey + sin($perp)*$head_w;
				$b2x = $ex - cos($perp)*$head_w;  $b2y = $ey - sin($perp)*$head_w;
				$out .= "\t\t<polygon points=\"{$tip_x},{$tip_y} {$b1x},{$b1y} {$b2x},{$b2y}\"\n";
				$out .= "\t\t\tstyle=\"fill:#{$col};stroke:none;opacity:1\"/>\n";
			}
		}

		return $out;
	}

	/** Converts svg into given format */
	function convert($svg, $fmt, $size) {
		global $CONVERT;
		$opts = gen_image_opts($fmt, $size, "svg:-", "$fmt:-");
		$descriptorspec = array(0 => array("pipe", "r"), 1 => array("pipe", "w"));
		$convert = proc_open("$CONVERT $opts", $descriptorspec, $pipes);
		fwrite($pipes[0], $svg);
		fclose($pipes[0]);
		$img = null;
		while(!feof($pipes[1])) {
			$img .= fread($pipes[1], 1024);
		}
		fclose($pipes[1]);
		proc_close($convert);
		return $img;
	}

	/** Alternative version using files rather than pipes,
	not desired because of collision possibilities.. */
	function convert_file($svg, $fmt, $size) {
		global $CONVERT;
		$svgfile = fopen("/tmp/visualcube.svg", 'w');
		fwrite($svgfile, $svg);
		fclose($svgfile);
		$opts = gen_image_opts($fmt, $size, '/tmp/visualcube.svg', "/tmp/visualcube.$fmt");
		$rsvg = exec("$CONVERT $opts");
		$imgfile = fopen("/tmp/visualcube.$fmt", 'r');
		$img = null;
		while($imgfile and !feof($imgfile)) {
			$img .= fread($imgfile, 1024);
		}
		fclose($imgfile);
		return $img;
	}

	/** Generate ImageMagick options depending on format */
	function gen_image_opts($fmt, $size, $infile, $outfile){
		$inopts = ' -density 600 -resize '.$size.'x'.$size;
		$outopts = ' -channel RGBA -alpha set';
//		$opts .= '+label "Generated by VisualCube"';
//		$opts .= ' -comment "Generated by VisualCube"';
//		$opts .= ' -caption "Generated by VisualCube"';
//		$opts = "-gaussian 1";
		switch($fmt){
			case 'png' : $inopts .= " -background none"; $outopts .= " -quality 100 -define png:format=png32";
			break;
			case 'gif' : $inopts .= " -background none";
			break;
			case 'ico' : $inopts .= " -background none";
			break;
			case 'jpg' : $outopts .= " -quality 90";
			break;

		}
		return "$inopts $infile $outopts $outfile";
	}

	/** Sends image to browser */
	function display_img($img, $fmt){
		$mime = $fmt;
		switch($fmt){
			case 'jpe' :
			case 'jpg' : $mime = 'jpeg'; break;
			case 'svg' : $mime = 'svg+xml'; break;
			case 'ico' : $mime = 'vnd.microsoft.icon'; break;
		}
		header("Content-type: image/$mime");
//		header("Content-Length: " . filesize($img) ."; ");
		echo $img;
	}





	// -----------------------------[ DB Access Functions ]--------------------------

	// Return result of sql query as array
	function get_arrays($mysql_con, $query){
		$result = mysqli_query($mysql_con, $query);
		$count = mysqli_num_rows($result);
		if($count <= 0) return null;
		$ary = Array($count);
		$i = 0;
		while($record = mysqli_fetch_array($result, MYSQLI_ASSOC)){
			$ary[$i] = $record;
			$i++;
		}
		return $ary;
	}
?>
