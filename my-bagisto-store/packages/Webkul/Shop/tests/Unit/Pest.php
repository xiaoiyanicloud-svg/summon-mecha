<?php

/*
|--------------------------------------------------------------------------
| Shop 纯单元测试的 TestCase 绑定
|--------------------------------------------------------------------------
|
| 外层 tests/Pest.php:19 把整个 ../packages/Webkul/Shop/tests 都绑到了
| ShopTestCase(继承 Tests\TestCase,会建数据库、跑迁移)。
|
| 但本目录下的测试是**纯函数**测试(签名、容器嗅探),不该背这个负担 ——
| 它们不需要 Laravel 容器、不需要数据库、不需要密钥,单独 new 出来就能跑。
| 就近的这个 Pest.php 把它们绑回 PHPUnit 原生 TestCase。
|
| ⚠ 如果哪天这里的测试抛 "could not find driver" 之类,说明绑定没生效,
|   退路是把这些测试挪到已登记 Unit suite 的 packages/Webkul/Core/tests/Unit。
|
*/

uses(PHPUnit\Framework\TestCase::class)->in('.');
