<?php

/**
 * 结论型默认值的守卫（迁移 000006 / 000007）。
 *
 * **什么是"结论型默认值"**：某一列的默认值代表一个"要通过某项检查才能得到"的结论。
 * 这类默认值不像崩溃那样显眼，但危害更大 —— 崩溃会被人发现，"默认正确"不会：
 * 页面照样显示"已平 / 可见 / 已备案"，而实际上什么都没核对过。
 *
 * 已经抓到的两处：
 *   - `reconciliation.status` 原默认 `matched`（已平）→ 对账还没做，就写着已平
 *   - `skill_review.status`   原默认 `visible`（可见）→ 内容安全还没跑，评价就对外可见
 *
 * 本文件把结论钉在**行为**上（插一行、不给值，看落库是什么），
 * 而不是只比表结构 —— 因为真正伤人的是"写入方没写、于是拿到了默认值"这条路径。
 *
 * 顺带记一笔**已经查过、确认没问题**的同类列（避免每次重新怀疑一遍）：
 *   - `audit_record.result`          默认 0 = RESULT_PENDING（待审）✅
 *   - `content_security_record.disposition` 默认 pending ✅
 *   - `compliance_filing.status`     默认 pending ✅
 *   - `skill_versions.status`        默认 draft（草稿）✅
 *   - `settlement` / `withdrawal` / `after_sale` / `support_ticket` / `incident` 默认均为起始态 ✅
 *   - `devices.verify_status`        默认 `registered`，语义就是"已登记、未核验" ✅
 */

/** 读某一列在表结构里的默认值（读的是真实库，不是模型里的声明）。 */
function columnDefault(string $table, string $column): ?string
{
    $row = DB::selectOne(
        'SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    );

    return $row?->d;
}

it('对账：不给 status 插一行，落库是 pending 而不是 matched', function () {
    $id = DB::table('reconciliation')->insertGetId([
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 对账的意义就是"两边能不能对上"。还没核对就写"已平"，
    // 等于真出了差额页面也照样显示已平 —— 这个功能就废了。
    expect(DB::table('reconciliation')->where('id', $id)->value('status'))->toBe('pending');
});

it('对账：表结构里的默认值也必须是 pending（防回退）', function () {
    expect(columnDefault('reconciliation', 'status'))->toBe('pending');
});

it('技能评价：不给 status 插一行，落库是 pending 而不是 visible', function () {
    $id = DB::table('skill_review')->insertGetId([
        'skill_id'    => 1,
        'customer_id' => 1,
        'content'     => '未经内容安全的用户文本',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    // 这个平台的立场是"先审后发"（还专门有内容安全留痕表）。
    // 评价是用户自由文本，默认就对外可见与那个立场不能并存。
    expect(DB::table('skill_review')->where('id', $id)->value('status'))->toBe('pending');
});

it('技能评价：表结构里的默认值也必须是 pending（防回退）', function () {
    expect(columnDefault('skill_review', 'status'))->toBe('pending');
});
