<?php
require_once(__DIR__ . "/../wild.php");

assert(wild("meklu/rbt_prs", "*/*") === true);
assert(wild("meklu/rbt_prs", "meklu/*") === true);
assert(wild("meklu/rbt_prs", "*/rbt_prs") === true);
assert(wild("meklu/rbt_prs", "meklu/rbt*") === true);
assert(wild("meklu/rbt_prs", "meklu/rbt_prs") === true);
assert(wild("meklu/rbt_prs", "xPaw/*") === false);
assert(wild("meklu/rbt_prs", "*/human") === false);
assert(wild("meklu/rbt_prs", "*/human*") === false);
assert(wild("meklu/rbt_prs", "meklu/rbt_prs---") === false);
assert(wild("meklu/rbt_prs", "---meklu/rbt_prs---") === false);
