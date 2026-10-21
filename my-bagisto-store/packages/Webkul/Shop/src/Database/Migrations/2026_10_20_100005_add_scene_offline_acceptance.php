<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_offline_acceptances', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->unsignedInteger('project_version');
            $t->string('state', 20)->default('pending_review');
            $t->unsignedInteger('row_version')->default(1);
            $t->char('template_hash', 64);
            $t->char('content_hash', 64);
            $t->json('items');
            $t->json('evidence');
            $t->text('encrypted_attestation');
            $t->dateTime('occurred_at');
            $t->unsignedInteger('recorded_by');
            $t->unsignedInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'project_version'], 'scene_offline_project_version_unique');
            $t->index(['state', 'id']);
        });
        Schema::table('scene_acceptance_records', function (Blueprint $t) {
            // 旧记录时间不回填猜测；created_at 仍表示平台写入时间。
            $t->dateTime('occurred_at')->nullable();
            $t->unsignedBigInteger('offline_acceptance_id')->nullable();
            $t->foreign('offline_acceptance_id')->references('id')->on('scene_offline_acceptances')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('客户线下确认、复核和验收事实不可通过回滚迁移删除。');
    }
};
