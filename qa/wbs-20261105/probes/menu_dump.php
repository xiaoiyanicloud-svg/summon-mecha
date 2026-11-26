<?php

require __DIR__.'/../../../my-bagisto-store/vendor/autoload.php';

$app = require __DIR__.'/../../../my-bagisto-store/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$menu = config('menu.admin');

foreach ($menu as $index => $item) {
    $key = is_array($item) ? ($item['key'] ?? '') : '';

    if (in_array($key, ['catalog', 'dashboard', 'sales'], true)) {
        echo "INDEX {$index}: ".json_encode($item, JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

echo 'count='.count($menu).PHP_EOL;
