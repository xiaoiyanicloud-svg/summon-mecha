<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_resources', function (Blueprint $t) {
            $t->unsignedInteger('created_by')->nullable();
            $t->char('identity_hash', 64)->nullable()->unique();
            $t->json('availability')->nullable();
            $t->unsignedInteger('buffer_before_minutes')->default(0);
            $t->unsignedInteger('buffer_after_minutes')->default(0);
            $t->string('verification_reference')->nullable();
        });
        Schema::table('scene_quotes', function (Blueprint $t) {
            $t->dateTime('confirmation_deadline')->nullable();
        });
        Schema::table('scene_reservations', function (Blueprint $t) {
            $t->json('resource_snapshot')->nullable();
        });
        Schema::create('scene_sku_mappings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('price_item_id');
            $t->foreign('price_item_id')->references('id')->on('scene_price_items')->restrictOnDelete();
            $t->unsignedInteger('channel_id');
            $t->foreign('channel_id')->references('id')->on('channels')->restrictOnDelete();
            $t->unsignedInteger('product_id');
            $t->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $t->string('mode', 20);
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 20)->default('draft');
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->string('reason', 2000);
            $t->timestamps();
            $t->unique(['price_item_id', 'channel_id']);
            $t->index(['product_id', 'mode']);
        });
        Schema::create('scene_quote_commercial', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id');
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedBigInteger('amount_minor');
            $t->char('reference_hash', 64)->unique();
            $t->text('encrypted_reference');
            $t->json('evidence');
            $t->char('content_hash', 64);
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('recorded_by');
            $t->unsignedInteger('reviewed_by')->nullable();
            $t->dateTime('occurred_at');
            $t->dateTime('reviewed_at')->nullable();
            $t->string('review_note', 2000)->nullable();
            $t->timestamps();
            $t->index(['quote_id', 'state']);
        });
        Schema::create('scene_order_parts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id')->unique();
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->unsignedInteger('order_id')->unique();
            $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $t->string('kind', 20);
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('资源核验、商务凭证及订单关联属于历史事实，请停写回退，不删除数据。');
    }
};
