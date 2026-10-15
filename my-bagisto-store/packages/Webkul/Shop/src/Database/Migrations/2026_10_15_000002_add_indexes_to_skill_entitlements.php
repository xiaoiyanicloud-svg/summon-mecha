<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5 · skill_entitlements 缺索引与唯一约束。
 *
 * 三件事,各自都有明确的查询在等它:
 *
 *   1. `license_key` 唯一键 —— 它是**授权的对外身份**(OrderController / SkillController /
 *      DeviceController 都把它透出给用户与设备;P1 的 device_pull 通道还会**拿它当身份**
 *      来拉技能)。没有唯一约束时,两行同 key 在库里是合法的,而"按 key 找授权"的代码
 *      (`->where('license_key', ...)->first()`)会静默取到任意一行 —— 即**把别人的技能发给你**。
 *      值由 `SkillEntitlement::newLicenseKey()`(Str::random(32))产生,列宽 char(32) 正好装下。
 *
 *      ⚠ 列可空:未下发/未绑定的授权 license_key 为 NULL。MySQL 的唯一索引**不约束 NULL**,
 *        多行 NULL 不冲突 —— 这正是我们要的(否则第二张未下发的授权就插不进去)。
 *
 *   2. `order_id` 索引 —— Listener\SkillDelivery 在订单取消/发货时按 order_id 反查授权
 *      (SkillDelivery.php:63、:216,OrderController.php:136),此前**全表扫描**。
 *
 *   3. `expires_at` 索引 —— 时效类查询与过期巡检用。
 *      ⚠ 已知重叠:2026_10_13_000005 建过 `idx_entitlements_status_expires (status, expires_at)`,
 *        其**尾列**就是 expires_at。本索引是为"不限定 status、只按时效筛"的场景准备的
 *        (按最左前缀规则复合索引服务不了这种查询);当前代码里还没有这种查询,
 *        所以它是**面向后续巡检/对账**的前置索引,不是当下热点。若确认永远不会有该查询,
 *        删掉 `expires_at` 那一项即可,order_id 与 license_key 两项不受影响。
 *
 * 索引名沿用建表迁移(2026_09_30_000001)的 `uk_/idx_skill_entitlements_*` 前缀,方便一眼看出属于哪张表。
 *
 * 加唯一键前已实测生产库无重复:`SELECT license_key, COUNT(*) ... HAVING COUNT(*) > 1` 返回 0 行
 * (共 10 行,10 行均非 NULL 且互不相同,无空串)。若在有重复的库上跑,本迁移会在建索引时**直接失败**——
 * 这是刻意的:宁可迁移中止,也不静默丢数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_entitlements')) {
            return;
        }

        Schema::table('skill_entitlements', function (Blueprint $table) {
            // 授权的对外身份,必须全局唯一 —— 见类注释第 1 点
            if (! Schema::hasIndex('skill_entitlements', 'uk_skill_entitlements_license_key')) {
                $table->unique('license_key', 'uk_skill_entitlements_license_key');
            }

            if (! Schema::hasIndex('skill_entitlements', 'idx_skill_entitlements_order_id')) {
                $table->index('order_id', 'idx_skill_entitlements_order_id');
            }

            if (! Schema::hasIndex('skill_entitlements', 'idx_skill_entitlements_expires_at')) {
                $table->index('expires_at', 'idx_skill_entitlements_expires_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_entitlements')) {
            return;
        }

        Schema::table('skill_entitlements', function (Blueprint $table) {
            if (Schema::hasIndex('skill_entitlements', 'idx_skill_entitlements_expires_at')) {
                $table->dropIndex('idx_skill_entitlements_expires_at');
            }

            if (Schema::hasIndex('skill_entitlements', 'idx_skill_entitlements_order_id')) {
                $table->dropIndex('idx_skill_entitlements_order_id');
            }

            if (Schema::hasIndex('skill_entitlements', 'uk_skill_entitlements_license_key')) {
                $table->dropUnique('uk_skill_entitlements_license_key');
            }
        });
    }
};
