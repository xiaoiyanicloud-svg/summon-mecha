<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_quotes', function (Blueprint $t) {
            $t->boolean('customer_visible')->default(false);
            $t->dateTime('sent_at')->nullable();
            $t->index(['request_id', 'customer_visible', 'version'], 'scene_quote_customer_history');
        });
        // 只有这些旧状态能证明已向客户发送；不猜测 voided 草稿是否曾公开，也不捏造发送时间。
        DB::table('scene_quotes')->whereIn('state', ['sent', 'accepted', 'rejected'])->update(['customer_visible' => true]);
        Schema::create('scene_quote_decisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id');
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->string('kind', 30);
            $t->string('principal_scope', 100);
            $t->text('reason');
            $t->char('terms_hash', 64);
            $t->char('content_hash', 64);
            $t->dateTime('created_at');
            $t->unique(['quote_id', 'kind']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('客户决策及已发送版本必须保留，请使用停写回退。');
    }
};
