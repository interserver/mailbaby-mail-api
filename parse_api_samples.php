<?php

$base = "/home/sites/mailbaby-mail-api/mailbaby-api-samples";
chdir($base);

foreach (['OpenAPI', 'Swagger'] as $gen) {
//    echo "[$gen generator]\n";

    $x = 0;
    $dirs = glob(strtolower($gen)."-client/"."*");

    foreach ($dirs as $path) {
        $x++;
        $f = basename($path);

        $s = str_replace('-', ' ', $f);
	$s = ucwords($s);

        if (strpos($s, ' ') === false) {
            $t = $f;
        } else {
            $parts = explode(' ', $s, 2);
            $l = $parts[0];
            $v = $parts[1];
            $t = "{$l} [{$v}]";
        }

        if ($x > 1000) {
            echo ", ";
        }

        $t = ucwords($t);

        echo "{$t} ({$gen} Gen)\n";
    }
}
