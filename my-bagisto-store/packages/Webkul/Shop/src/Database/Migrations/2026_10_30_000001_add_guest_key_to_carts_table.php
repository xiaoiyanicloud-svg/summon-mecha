<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API 游客购物车归属。
 *
 * 前端每个 API 请求都带持久化的 X-Guest-Key；没有这个列时，Bagisto 只把游客
 * 购物车写进 PHP Session，而 API 请求之间没有稳定 Session，表现就是
 * “加入购物车成功，刷新后为空”。本迁移只新增可空列与索引，不改历史 cart 行。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cart', 'guest_key')) {
            return;
        }

        Schema::table('cart', function (Blueprint $table) {
            $table->char('guest_key', 32)->nullable()->after('is_guest');
            $table->index(['guest_key', 'is_active'], 'idx_cart_guest_key_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cart', 'guest_key')) {
            return;
        }

        Schema::table('cart', function (Blueprint $table) {
            $table->dropIndex('idx_cart_guest_key_active');
            $table->dropColumn('guest_key');
        });
    }
};
