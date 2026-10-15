<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任务卡 D-7 · ⑨ —— 审核记录绑定「包指纹」。
 *
 * 为什么落在 `audit_record` 而不是 `skill_versions`：
 *   `audit_record` 天然就是"**判决当时看到的是什么**"的记录。放在版本表上会被后续
 *   上传覆盖掉 —— 那等于没记（改完包，记录里只剩新值，看不出曾经审过别的字节）。
 *
 * 可空：存量已发布/在审版本的历史记录没有这个信息，不能凭空补;
 *       非文件类交付物（cloud_config 等）也没有字节，指纹天然为 NULL。
 *       调用方必须区分"NULL = 无指纹可比"与"有值 = 当时是哪一份"。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_record') && ! Schema::hasColumn('audit_record', 'package_fingerprint')) {
            Schema::table('audit_record', function (Blueprint $table) {
                // sha256 十六进制 = 64 字符
                $table->char('package_fingerprint', 64)->nullable()->after('audited_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_record') && Schema::hasColumn('audit_record', 'package_fingerprint')) {
            Schema::table('audit_record', fn (Blueprint $table) => $table->dropColumn('package_fingerprint'));
        }
    }
};
