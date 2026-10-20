<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-13 · `ecosystem_export_files`（专用报名导出）。
 *
 * 通用 DataGrid 导出旁路保持关闭；导出只走本表：
 *   · 文件在私有盘，路径不进入前端；
 *   · 下载用一次性短期 token（只存哈希），默认 10 分钟过期；
 *   · 下载时重新校验权限与活动范围；权限撤回后不能继续下载；
 *   · `fields` 只记录本次导出的字段白名单，便于审计“导出了什么”。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_export_files';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->char('token_hash', 64);
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('admin_id');

            $table->json('filters')->nullable();
            $table->json('fields');

            $table->string('file_path', 255);
            $table->dateTime('expires_at');
            $table->dateTime('downloaded_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            $table->unique('token_hash', 'uk_ecosystem_export_files_hash');
            $table->index(['event_id', 'expires_at'], 'idx_ecosystem_export_files_event_expires');
            $table->index(['admin_id', 'created_at'], 'idx_ecosystem_export_files_admin_created');

            $table->foreign('event_id', 'fk_ecosystem_export_files_event')
                ->references('id')->on('events')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
