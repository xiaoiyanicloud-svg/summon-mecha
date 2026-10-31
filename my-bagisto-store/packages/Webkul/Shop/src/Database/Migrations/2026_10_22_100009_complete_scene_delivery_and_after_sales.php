<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_projects', function (Blueprint $t) {
            $t->string('paused_from_state', 30)->nullable();
            $t->string('incident_ref', 80)->nullable();
        });

        Schema::table('scene_project_changes', function (Blueprint $t) {
            $t->bigInteger('price_delta_minor')->default(0);
            $t->json('evaluation')->nullable();
            $t->unsignedInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->dateTime('customer_accepted_at')->nullable();
            $t->text('decision_note')->nullable();
        });

        Schema::table('scene_service_tickets', function (Blueprint $t) {
            $t->string('scope', 30)->default('platform_service');
            $t->unsignedInteger('order_item_id')->nullable();
            $t->unsignedBigInteger('entitlement_id')->nullable();
            $t->unsignedBigInteger('refund_proposal_minor')->default(0);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('变更履约、售后工单及事故事实不可通过回滚迁移删除。');
    }
};
