<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客服会话满意度评价（CSAT）。
 *
 * 头部平台客服中心的标配：用户在被服务完之后给一次评价，平台据此判断
 * 「回复快不快、问题解没解决、态度好不好」。此前客服中心只有状态流转，
 * 没有任何质量信号 —— 工单关掉就结束了，谁做得好、哪类问题难，全凭感觉。
 *
 * 只做**一张工单一次评价**（`rated_at` 非空即视为已评），不做追评/改评：
 * 运营看的是当时那一次服务的真实感受，允许改评会让数据失真。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket') || Schema::hasColumn('support_ticket', 'rating')) {
            return;
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('ended_at')->comment('1-5，空=未评价');
            $table->json('rating_tags')->nullable()->after('rating')->comment('评价标签 key 数组');
            $table->string('rating_comment', 500)->nullable()->after('rating_tags');
            $table->timestamp('rated_at')->nullable()->after('rating_comment');
            $table->index(['rated_at'], 'idx_ticket_rated_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_ticket') || ! Schema::hasColumn('support_ticket', 'rating')) {
            return;
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_rated_at');
            $table->dropColumn(['rating', 'rating_tags', 'rating_comment', 'rated_at']);
        });
    }
};
