<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A5 · 事故处置记录改成 append-only 表。
 *
 * 此前处置记录写在 `incident.handling_log` 一列里,每次处置 = 读出整列 → 追加 → 整列写回。
 * 两个管理员并发处置,后写覆盖先写,一条记录消失。
 *
 * 这里建独立表(一行一条),并把历史 JSON 里的记录**搬进来**,保证审计不断档。
 * `incident.handling_log` 列保留(不再写入):一是历史数据不丢,二是让迁移可回滚。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_handling_log')) {
            Schema::create('incident_handling_log', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('incident_id');
                $table->unsignedInteger('admin_id')->nullable();
                $table->string('status', 16)->nullable();
                $table->string('responsibility', 16)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('incident_id', 'idx_incident_handling_incident');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_handling_log');
    }

    /**
     * 把 `incident.handling_log` 里的历史记录搬进新表。
     */
    protected function backfill(): void
    {
        if (! Schema::hasTable('incident') || ! Schema::hasColumn('incident', 'handling_log')) {
            return;
        }

        $rows = DB::table('incident')
            ->whereNotNull('handling_log')
            ->where('handling_log', '!=', '[]')
            ->select('id', 'handling_log')
            ->get();

        foreach ($rows as $row) {
            $entries = json_decode((string) $row->handling_log, true);

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                DB::table('incident_handling_log')->insert([
                    'incident_id'    => $row->id,
                    'admin_id'       => $entry['admin_id'] ?? null,
                    'status'         => $entry['status'] ?? null,
                    'responsibility' => $entry['responsibility'] ?? null,
                    'note'           => $entry['note'] ?? null,
                    'created_at'     => $entry['at'] ?? null,
                ]);
            }
        }
    }
};
