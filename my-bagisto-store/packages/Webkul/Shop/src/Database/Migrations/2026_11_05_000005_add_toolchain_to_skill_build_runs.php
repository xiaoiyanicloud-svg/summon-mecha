<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B5.5：每次构建 Run 记录工具链指纹。
 *
 * `container_image_digest` 为 null、状态 NOT_RUN —— 当前没有独立构建镜像，
 * 这一点必须在数据里可见，而不是只写在文档里。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_build_runs') && ! Schema::hasColumn('skill_build_runs', 'toolchain_json')) {
            Schema::table('skill_build_runs', function (Blueprint $table) {
                $table->text('toolchain_json')->nullable()->after('build_log');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_build_runs') && Schema::hasColumn('skill_build_runs', 'toolchain_json')) {
            Schema::table('skill_build_runs', function (Blueprint $table) {
                $table->dropColumn('toolchain_json');
            });
        }
    }
};
