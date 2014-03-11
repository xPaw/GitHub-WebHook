<?php
function wild($str, $expr) {
	$delim = false;
	$ret = true;
	for (
		$si = 0, $ei = 0;
		$si < strlen($str) || $ei < strlen($expr);
		$ei += 1
	) {
		if ($expr[$ei] === '*') {
			/* iterate through the string until we hit
			 * the delimiter */
			$delim = substr($expr, $ei + 1, 1);
			while ($si < strlen($str)) {
				if ($str[$si] === $delim) {
					$delim = false;
					break;
				}
				$si += 1;
			}
		} else {
			if (substr($str, $si, 1) !== substr($expr, $ei, 1)) {
				$ret = false;
				break;
			}
			$si += 1;
		}
	}
	return $ret;
}
