<?php

class Utils {
	public static function Stop($code = 200, $message = '', $flush = false, $content_type = 'application/json', $unlink = null) {
		if ($flush) ob_end_flush();
		http_response_code($code);
		header('content-type: ' . $content_type);
		if (!is_null($unlink) && file_exists($unlink)) @unlink($unlink);
		die($message);
	}

}