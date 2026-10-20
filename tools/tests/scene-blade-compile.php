<?php

// 只编译 Blade 指令并检查 PHP 语法，不冒充真实 Admin 布局/权限/会话渲染。
require dirname(__DIR__, 2).'/my-bagisto-store/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$target = $root.'/.claude/backup/scene-sc1-sc5-20261020/blade-qa';
if (! is_dir($target)) {
    mkdir($target, 0700, true);
}
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $target);
$compiler->withoutComponentTags();
$files = glob($root.'/my-bagisto-store/packages/Webkul/Admin/src/Resources/views/catalog/scene-workflow/*.blade.php');
foreach ($files as $file) {
    file_put_contents($target.'/'.basename($file).'.php', $compiler->compileString(file_get_contents($file)));
}
echo json_encode(['compiled' => count($files), 'component_layout_rendered' => false, 'output' => $target], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
