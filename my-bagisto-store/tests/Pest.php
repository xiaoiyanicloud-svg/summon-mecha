<?php

ini_set('memory_limit', '1024M');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(Webkul\Admin\Tests\AdminTestCase::class)->in('../packages/Webkul/Admin/tests');
uses(Webkul\Core\Tests\CoreTestCase::class)->in('../packages/Webkul/Core/tests');
uses(Webkul\DataGrid\Tests\DataGridTestCase::class)->in('../packages/Webkul/DataGrid/tests');
uses(Webkul\Shop\Tests\ShopTestCase::class)->in('../packages/Webkul/Shop/tests');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * 生成一个**同一进程内保证唯一**的测试单号(task_no / incident_no / ticket_no …)。
 *
 * 为什么不能用「前缀 + now()->format('ymdHis') + 窄随机数」:
 *   秒级时间戳在同一秒内是常量,唯一性全靠后面那点随机数。区间一窄
 *   (如 random_int(100, 999) 只有 900 档),同一个用例里连着建两三条记录,
 *   撞号概率就是生日问题级别 —— 实测 P11 用例同秒两条抽到同一随机数,
 *   直接撞 deploy_task 的 uk_deploy_task_no,表现为"随机闪红":
 *   单跑绿、连跑红,让人误判成自己引入的回归,比稳定失败更坏。
 *
 * 本函数用**进程内自增计数**兜底唯一性:$seq 在一整个 Pest 进程里单调递增、
 * 永不回绕,所以无论时间戳与随机数怎么撞,单号都不会重复。再拼 ymdHis + 随机数,
 * 只是让单号在日志里可读、跨进程也大概率不同。
 *
 * ⚠ 唯一性只在**同一进程内**成立(测试靠 DatabaseTransactions 逐用例回滚,
 *   不会跨用例残留,故足够)。若将来改并行 / 多进程共享同一个库,得另想办法。
 * ⚠ 各 *_no 列宽 40,前缀请勿超过约 10 字符。
 */
function testUniqueNo(string $prefix = 'T'): string
{
    static $seq = 0;

    return $prefix.now()->format('ymdHis')
        .str_pad((string) ++$seq, 4, '0', STR_PAD_LEFT)
        .random_int(1000, 9999);
}
