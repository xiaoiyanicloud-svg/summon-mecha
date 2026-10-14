<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 orders 加「本机游客标识」。
 *
 * 修的 bug:OrderController::guestIndex() 原来用 whereNull('customer_id') 取游客单,
 * 这会把**所有人的**游客订单都返回给任何一个访客 —— A 打开订单页能看到 B 的游客单。
 * 前端没有任何本机标识可用,所以这个洞必须从数据层补。
 *
 * guest_key 由前端首次生成后持久化在本地,随每个请求以 X-Guest-Key 头带来;
 * 游客下单时落库,之后游客订单列表/取消都按它过滤。
 *
 * ⚠ 无法回填:历史游客单没有这个标识,分不清是哪台设备下的 —— 这是刻意取舍,
 *   宁可让少数历史游客单查不到,也不继续把所有人的订单暴露给所有人。
 *   这些单在后台依旧完整可见,客服可按手机号人工处理。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('orders', 'guest_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->char('guest_key', 32)->nullable()->after('is_guest');
            $table->index('guest_key', 'idx_orders_guest_key');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('orders', 'guest_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_guest_key');
            $table->dropColumn('guest_key');
        });
    }
};
