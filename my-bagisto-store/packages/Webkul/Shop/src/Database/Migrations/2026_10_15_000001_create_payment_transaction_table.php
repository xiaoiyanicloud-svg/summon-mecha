<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6 · 支付流水表 —— 回调成功即落一行,并以其唯一键做幂等。
 *
 * 为什么必须新建表(而不是复用原生 order_transactions):
 *   1. `orders` 只存"当前状态",回调把订单翻成 processing 后
 *      **网关交易号/商户订单号/金额全部丢弃**,既无幂等键,也无对账源。
 *      `OpsMetricsService::notConnected()` 自己就写着「支付交易号 out_trade_no 从未持久化」。
 *   2. 原生 `order_transactions.invoice_id` 是 **NOT NULL**,而本项目的 TOB 下单管道
 *      **根本不建发票** —— 写不进去。
 *   3. 原生 `order_transactions.transaction_id` **无唯一索引**,不能当幂等键
 *      (并发重复回调会各插一行)。
 *
 * 所以本表把 `out_trade_no` 设为**唯一键**:它是"同一笔支付"的天然身份,
 * 重复回调撞唯一键即视为已处理 —— 幂等不再依赖"先查订单状态再改"的 check-then-act。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_transaction')) {
            return;
        }

        Schema::create('payment_transaction', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id')->nullable();
            // 商户订单号 —— 唯一键,幂等的锚点
            $table->string('out_trade_no', 64);
            // 网关侧交易号(mock 为 MOCKTXN...,真实网关为微信 transaction_id)
            $table->string('transaction_id', 64)->nullable();
            // 渠道标识(mock / wechat / ...)
            $table->string('gateway', 32)->nullable();
            $table->decimal('amount', 12, 4)->default(0);
            $table->string('status', 16)->default('paid'); // paid / refunded ...
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique('out_trade_no', 'uk_payment_txn_out_trade_no');
            $table->index('order_id', 'idx_payment_txn_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transaction');
    }
};
