<?php

// 编译全部场景 Blade 并实际校验产物语法；不冒充真实 Admin 布局/会话渲染。
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$target = $root.'/.claude/backup/scene-sc1-sc5-20261020/blade-qa';
if (! is_dir($target)) {
    mkdir($target, 0700, true);
}
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $target);
$compiler->withoutComponentTags();
$files = glob($root.'/my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/scene-workflow/*.blade.php');
$checks = [];
foreach ($files as $file) {
    $output = $target.'/'.basename($file).'.php';
    file_put_contents($output, $compiler->compileString(file_get_contents($file)));
    // 在同一个 PHP 引擎进行实际语法解析，避免 Windows 每模板子进程的启动超时。
    try {
        token_get_all(file_get_contents($output), TOKEN_PARSE);
        $checks[] = ['template' => basename($file), 'syntax_ok' => true];
    } catch (ParseError $e) {
        $checks[] = ['template' => basename($file), 'syntax_ok' => false, 'error' => $e->getMessage()];
    }
}
$failed = count(array_filter($checks, fn ($r) => ! $r['syntax_ok']));
echo json_encode(['compiled' => count($files), 'failed' => $failed, 'checks' => $checks, 'component_layout_rendered' => false, 'output' => $target], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed ? 1 : 0);
