<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Models\SkillEntitlement;

/**
 * 加固第六轮 · D5 —— skill_entitlements 的索引与唯一约束。
 *
 * 迁移:`Database/Migrations/2026_10_15_000002_add_indexes_to_skill_entitlements.php`
 *
 * 这里测的是**约束真的生效**,而不只是"迁移跑过了":
 *   · 索引存在(信息架构层)—— 迁移写错列名时,Schema 会静默成功(建在别的列上);
 *   · 唯一键真的拦得住重复 license_key —— 这是 D5 的全部意义所在;
 *   · 多行 NULL 仍能共存 —— **回归防线**:万一有人把列改成 NOT NULL 或给空串默认值,
 *     "第二张未下发的授权插不进去"会立刻炸出来。
 */

const D5_TABLE = 'skill_entitlements';

function d5IndexExists(string $name): bool
{
    return DB::table('information_schema.statistics')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', D5_TABLE)
        ->where('index_name', $name)
        ->exists();
}

/** 直接走 Eloquent,绕开所有业务校验 —— 测的是数据库约束本身。 */
function d5MakeEntitlement(?string $licenseKey): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => 1,
        'order_id'         => 900000 + random_int(1, 90000),
        // ⚠ order_item_id 有唯一键且 NOT NULL —— 不显式给值会全部落到 0 而互相撞车
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => SkillEntitlement::STATUS_PENDING_BIND,
        'delivery_channel' => 'app',
        'license_key'      => $licenseKey,
    ]);
}

it('D5 · 迁移三个索引都在表上', function () {
    // 逐一按名断言(而不是只看 count)—— 名字即契约,改名的迁移应该被这条测试拦住
    expect(d5IndexExists('uk_skill_entitlements_license_key'))->toBeTrue()
        ->and(d5IndexExists('idx_skill_entitlements_order_id'))->toBeTrue()
        ->and(d5IndexExists('idx_skill_entitlements_expires_at'))->toBeTrue();
});

/**
 * 取某索引的定义。
 *
 * ⚠ 用 SHOW INDEX 而不是查 information_schema:MySQL 8 的 information_schema 列名是
 * **大写**(COLUMN_NAME / NON_UNIQUE),直接 `$row->column_name` 会得到
 * "Undefined property"。SHOW INDEX 的返回列名在各版本一致 —— 实测踩过。
 */
function d5IndexDefinition(string $name): array
{
    $rows = DB::select('SHOW INDEX FROM `'.D5_TABLE.'` WHERE Key_name = ?', [$name]);

    if (! $rows) {
        return [];
    }

    return [
        'columns'  => array_map(fn ($r) => $r->Column_name, $rows),
        'unique'   => (int) $rows[0]->Non_unique === 0,
    ];
}

it('D5 · 唯一键建在 license_key 单列上,且是唯一索引', function () {
    expect(d5IndexDefinition('uk_skill_entitlements_license_key'))
        ->toBe(['columns' => ['license_key'], 'unique' => true]);
});

it('D5 · order_id / expires_at 是普通索引(不是唯一键)', function () {
    foreach (['idx_skill_entitlements_order_id' => 'order_id', 'idx_skill_entitlements_expires_at' => 'expires_at'] as $index => $column) {
        // 一个订单可以有多条授权、同一时刻可以有多条过期 —— 唯一键会直接写崩业务
        expect(d5IndexDefinition($index))
            ->toBe(['columns' => [$column], 'unique' => false]);
    }
});

it('D5 · 重复的 license_key 被数据库拒绝', function () {
    $key = SkillEntitlement::newLicenseKey();

    d5MakeEntitlement($key);

    // 这条若插入成功,等于"同一把钥匙可能对应两条授权" ——
    // 而 P1 的 device_pull 通道是**拿 license_key 当身份**的,那会把别人的技能发出去。
    expect(fn () => d5MakeEntitlement($key))->toThrow(QueryException::class);
});

it('D5 · 多行 NULL license_key 可以共存(未下发的授权不被约束挡住)', function () {
    d5MakeEntitlement(null);
    d5MakeEntitlement(null);

    expect(SkillEntitlement::whereNull('license_key')->count())->toBeGreaterThanOrEqual(2);
});

it('D5 · 空串不算 NULL —— 它会被唯一键挡住(所以代码里必须写 null 而不是空串)', function () {
    d5MakeEntitlement('');

    // 这条断言是给后来者看的:如果哪天有代码把 license_key 初始化成 '' 而不是留 NULL,
    // 症状就是"第二张授权建不出来",而且报错信息只提唯一键,看不出是空串的问题。
    expect(fn () => d5MakeEntitlement(''))->toThrow(QueryException::class);
});

it('D5 · 迁移文件已登记(migrate:status 里不是 Pending)', function () {
    $name = '2026_10_15_000002_add_indexes_to_skill_entitlements';

    expect(DB::table('migrations')->where('migration', $name)->exists())->toBeTrue()
        // Schema::hasIndex 是迁移里用的守卫,顺带确认它在当前版本可用
        ->and(Schema::hasIndex(D5_TABLE, 'uk_skill_entitlements_license_key'))->toBeTrue();
});
