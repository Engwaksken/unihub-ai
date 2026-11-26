<?php

// Reports YAML flow-mapping lines that fail to parse, then whether each whole file parses.
// Usage: php tests/tools/yaml-lint.php ../docs/api/public-openapi.yaml ../docs/api/management-openapi.yaml

require __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$failed = false;
foreach (array_slice($argv, 1) as $f) {
    foreach (file($f) as $i => $l) {
        $t = preg_replace('/^- /', '', trim($l));
        $val = preg_match('/^[^{]*?:\s*(\{.*\})\s*$/', $t, $m) ? $m[1] : (str_starts_with($t, '{') ? $t : null);
        if ($val === null) {
            continue;
        }
        try {
            Yaml::parse('x: '.$val);
        } catch (Throwable) {
            echo basename($f).':'.($i + 1).': '.substr(trim($l), 0, 120)."\n";
        }
    }
    try {
        Yaml::parseFile($f);
        echo basename($f)." PARSES OK\n";
    } catch (Throwable $e) {
        $failed = true;
        echo basename($f).' FAIL: '.substr($e->getMessage(), 0, 160)."\n";
    }
}
exit($failed ? 1 : 0);
