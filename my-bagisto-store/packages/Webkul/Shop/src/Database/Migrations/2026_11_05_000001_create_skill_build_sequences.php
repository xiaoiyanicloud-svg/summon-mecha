<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2：构建 run_number 的单一分配序列。
 *
 * 为什么不是 `max(run_number)+1`：
 *   两个 worker 在同一 build_key 上并发构建时，max+1 会读到同一个最大值，
 *   随后一个插入成功、一个撞 `uk_build_runs_key_run`。撞的那个若只是裸
 *   QueryException 就重试，会把死锁/权限/磁盘错误也当成撞号，掩盖根因。
 *
 * 本表用 `build_key` 唯一键 + 行锁串行化同一 build_key 的分配；分配与
 * `skill_build_runs` 插入在同一事务内完成，回滚时序号一起回滚，不产生空洞。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_build_sequences')) {
            return;
        }

        Schema::create('skill_build_sequences', function (Blueprint $table) {
            $table->increments('id');
            $table->string('build_key', 128)->unique('uk_build_sequences_key');
            $table->unsignedInteger('last_run_number')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_build_sequences');
    }
};
