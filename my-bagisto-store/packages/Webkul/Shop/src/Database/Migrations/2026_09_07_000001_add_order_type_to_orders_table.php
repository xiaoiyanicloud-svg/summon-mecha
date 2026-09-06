<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 订单类型列:定制/租用/购买/方案/技能/配件 后台分类筛选的数据地基。
 *
 * 背景:TOB 订单的类型此前只存在于条目名(如「宇树Go2【租赁】(租期3天)」),无法在后台按类型筛选。
 * 本迁移加可空列 order_type,并从存量条目名的【标签】回填。
 * 注意:历史技能/配件单当年按【购买】落库,已不可区分,回填后归入 buy(自本迁移起新单才完整)。
 * 回滚:down() 删列即完全恢复。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_type')->nullable()->index()->after('status');
        });

        // 回填:取每个订单的首个条目名解析类型(tob-order 单订单单条目;原生商城单默认 buy)
        DB::statement("
            UPDATE ".DB::getTablePrefix()."orders o
            JOIN (
                SELECT order_id, MIN(id) AS first_item_id
                FROM ".DB::getTablePrefix()."order_items
                GROUP BY order_id
            ) fi ON fi.order_id = o.id
            JOIN ".DB::getTablePrefix()."order_items oi ON oi.id = fi.first_item_id
            SET o.order_type = CASE
                WHEN oi.name LIKE '%【定制】%' THEN 'custom'
                WHEN oi.name LIKE '%【租赁】%' THEN 'rent'
                WHEN oi.name LIKE '%【方案】%' THEN 'plan'
                WHEN oi.name LIKE '%【技能】%' THEN 'skill'
                WHEN oi.name LIKE '%【配件】%' THEN 'accessory'
                ELSE 'buy'
            END
            WHERE o.order_type IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_type']);
            $table->dropColumn('order_type');
        });
    }
};
