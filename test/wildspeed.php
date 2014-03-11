<?php
require_once(__DIR__ . "/../wild.php");
function wild_regex($str, $expr) {
	$ret = preg_match("#^".strtr(preg_quote($expr, '#'), array('\*' => '.*', '\?' => '.'))."$#i", $str);
	if ($ret > 0) {
		return true;
	}
	return false;
}

function wildspeed($str, $expr, $res) {
	$s = microtime(true);
	$ret = wild($str, $expr);
	$e = microtime(true);
	printf("%.3fus wild\n", 1000000 * ($e - $s));
	assert($ret === $res);
	$s = microtime(true);
	$ret = wild_regex($str, $expr);
	$e = microtime(true);
	printf("%.3fus wild_regex\n", 1000000 * ($e - $s));
	assert($ret === $res);
}

wildspeed("meklu/rbt_prs", "*/*", true);
wildspeed("meklu/rbt_prs", "meklu/*", true);
wildspeed("meklu/rbt_prs", "*/rbt_prs", true);
wildspeed("meklu/rbt_prs", "meklu/rbt*", true);
wildspeed("meklu/rbt_prs", "meklu/rbt_prs", true);
wildspeed("meklu/rbt_prs", "xPaw/*", false);
wildspeed("meklu/rbt_prs", "*/human", false);
wildspeed("meklu/rbt_prs", "*/human*", false);
wildspeed("meklu/rbt_prs", "meklu/rbt_prs---", false);
wildspeed("meklu/rbt_prs", "---meklu/rbt_prs---", false);
