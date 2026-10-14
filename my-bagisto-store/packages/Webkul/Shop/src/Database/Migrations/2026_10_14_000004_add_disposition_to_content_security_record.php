<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 `content_security_record` 补上**人工处置**字段。
 *
 * 原表（`2026_10_14_000001_create_ops_safety_tables.php`）只记"机器扫出了什么"，
 * 没有任何地方记"人看了之后怎么处置" —— 而内容安全恰恰是"机器扫 + 人工判"两段。
 * 少了这半张表，一个被判 block 的技能到底是被下架了、还是被人工放行了，事后无从追溯。
 *
 * 四个字段：
 *   disposition       pending / cleared（人工放行）/ actioned（已按违规处置）
 *   disposition_note  处置说明（给后来的人看为什么这么判）
 *   disposition_by / disposition_at  谁在什么时候处置的
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_security_record')
            || Schema::hasColumn('content_security_record', 'disposition')
        ) {
            return;
        }

        Schema::table('content_security_record', function (Blueprint $table) {
            $table->string('disposition', 16)->default('pending')->after('raw');
            $table->text('disposition_note')->nullable()->after('disposition');
            $table->unsignedInteger('disposition_by')->nullable()->after('disposition_note');
            $table->timestamp('disposition_at')->nullable()->after('disposition_by');

            $table->index(['result', 'disposition'], 'idx_cs_result_disposition');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('content_security_record')
            || ! Schema::hasColumn('content_security_record', 'disposition')
        ) {
            return;
        }

        Schema::table('content_security_record', function (Blueprint $table) {
            $table->dropIndex('idx_cs_result_disposition');
            $table->dropColumn(['disposition', 'disposition_note', 'disposition_by', 'disposition_at']);
        });
    }
};
