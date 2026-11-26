<?php

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$menu = config('menu.admin');
$flat = [];

foreach ($menu as $item) {
    if (is_array($item) && isset($item['key'])) {
        $flat[$item['key']] = $item;
    }
}

$tree = Illuminate\Support\Arr::undot(Illuminate\Support\Arr::dot($flat));

$walk = function ($node, string $path) use (&$walk): void {
    if (! is_array($node)) {
        return;
    }

    $hasArrayChild = false;

    foreach ($node as $value) {
        if (is_array($value)) {
            $hasArrayChild = true;
        }
    }

    if ($hasArrayChild && ! array_key_exists('name', $node)) {
        echo "MISSING name at {$path}: keys=".implode(',', array_keys($node)).PHP_EOL;
    }

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $walk($value, $path.'.'.$key);
        }
    }
};

$walk($tree, 'root');
echo "done\n";
