<?php

class Utils {
	public static function Stop($code = 200, $message = '', $flush = false, $content_type = 'application/json', $unlink = null) {
		if ($flush) ob_end_flush();
		http_response_code($code);
		header('content-type: ' . $content_type);
		if (!is_null($unlink) && file_exists($unlink)) @unlink($unlink);
		die($message);
	}

    // Utility Functions

    // public static function fillColor($val) {
    //     return ($val % 2 == 0) ? self::FILL_COLOR_LIGHT : self::FILL_COLOR_DARK;
    // }

    // public static function opacity($val)
    // {
    //     return self::map($val, 0, 15, self::OPACITY_MIN, self::OPACITY_MAX);
    // }

    // public static function hexVal($index, $len)
    // {
    //     return hexdec(substr(self::hash, $index, $len));
    // }

    // PHP implementation of Processing's map function
    // http://processing.org/reference/map_.html
    public static function map($value, $vMin, $vMax, $dMin, $dMax)
    {
        $vValue = floatval($value);
        $vRange = $vMax - $vMin;
        $dRange = $dMax - $dMin;
        return ($vValue - $vMin) * $dRange / $vRange + $dMin;
    }

    // Color Functions
    public static function hexToHSL($color)
    {
        $color = trim($color, '#');
        $R = hexdec($color[0].$color[1]);
        $G = hexdec($color[2].$color[3]);
        $B = hexdec($color[4].$color[5]);

        $HSL = array();

        $var_R = ($R / 255);
        $var_G = ($G / 255);
        $var_B = ($B / 255);

        $var_Min = min($var_R, $var_G, $var_B);
        $var_Max = max($var_R, $var_G, $var_B);
        $del_Max = $var_Max - $var_Min;

        $L = ($var_Max + $var_Min)/2;

        if ($del_Max == 0)
        {
            $H = 0;
            $S = 0;
        }
        else
        {
            if ( $L < 0.5 ) $S = $del_Max / ( $var_Max + $var_Min );
            else            $S = $del_Max / ( 2 - $var_Max - $var_Min );

            $del_R = ( ( ( $var_Max - $var_R ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
            $del_G = ( ( ( $var_Max - $var_G ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;
            $del_B = ( ( ( $var_Max - $var_B ) / 6 ) + ( $del_Max / 2 ) ) / $del_Max;

            if      ($var_R == $var_Max) $H = $del_B - $del_G;
            else if ($var_G == $var_Max) $H = ( 1 / 3 ) + $del_R - $del_B;
            else if ($var_B == $var_Max) $H = ( 2 / 3 ) + $del_G - $del_R;

            if ($H<0) $H++;
            if ($H>1) $H--;
        }

        $HSL['h'] = ($H*360);
        $HSL['s'] = $S;
        $HSL['l'] = $L;

        return $HSL;
    }

    public static function hexToRGB($hex) {
        $hex = str_replace("#", "", $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    public static function rgbToHSL($r, $g, $b) {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max == $min) {
            $h = $s = 0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            switch ($max) {
                case $r:
                    $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $h = ($b - $r) / $d + 2;
                    break;
                case $b:
                    $h = ($r - $g) / $d + 4;
                    break;
            }
            $h /= 6;
        }
        $h = floor($h * 360);
        $s = floor($s * 100);
        $l = floor($l * 100);
        return ['h' => $h, 's' => $s, 'l' => $l];
    }

    public static function hslToRGB ($h, $s, $l) {
        $h += 360;
        $c = ( 1 - abs( 2 * $l - 1 ) ) * $s;
        $x = $c * ( 1 - abs( fmod( ( $h / 60 ), 2 ) - 1 ) );
        $m = $l - ( $c / 2 );

        if ( $h < 60 ) {
            $r = $c;
            $g = $x;
            $b = 0;
        } else if ( $h < 120 ) {
            $r = $x;
            $g = $c;
            $b = 0;
        } else if ( $h < 180 ) {
            $r = 0;
            $g = $c;
            $b = $x;
        } else if ( $h < 240 ) {
            $r = 0;
            $g = $x;
            $b = $c;
        } else if ( $h < 300 ) {
            $r = $x;
            $g = 0;
            $b = $c;
        } else {
            $r = $c;
            $g = 0;
            $b = $x;
        }

        $r = ( $r + $m ) * 255;
        $g = ( $g + $m ) * 255;
        $b = ( $b + $m  ) * 255;

        return array( 'r' => floor( $r ), 'g' => floor( $g ), 'b' => floor( $b ) );

    }

    //NOT USED
    public static function rgbToHex($r, $g, $b) {
        $hex = "#";
        $hex .= str_pad(dechex($r), 2, "0", STR_PAD_LEFT);
        $hex .= str_pad(dechex($g), 2, "0", STR_PAD_LEFT);
        $hex .= str_pad(dechex($b), 2, "0", STR_PAD_LEFT);
        return $hex;
    }

}