<?php

// 仅供隔离故障测试，不能配置为生产安全扫描器。
$path = $argv[count($argv) - 1] ?? '';
if (! is_file($path) || ! str_starts_with(file_get_contents($path), '%PDF-') || str_contains(file_get_contents($path), 'EICAR')) {
    exit(1);
}
if (in_array('--tamper', $argv, true)) {
    file_put_contents($path, 'changed after scan');
}
exit(0);
