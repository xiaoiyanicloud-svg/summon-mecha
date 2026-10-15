<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B-10 · 工单 ↔ 事故互链(计划 §4.16/§4.19)。
 *
 * 此前 `Services/Incident/` 与工单服务之间**零互引** —— 用户投诉开的是工单,
 * 事故走的是另一条完全不相交的录入。而计划要求的链是:
 * **用户投诉 → 工单 → 若定性为事故 → incident**。
 *
 * 这里在 **incident 一侧**存 `ticket_id`(肇事工单),作为唯一真相:
 *   - incident → 来源工单:直读本列;
 *   - 工单 → 是否已定性事故:反查 `incident where ticket_id = ?`(不双写,避免两处漂移)。
 *
 * ⚠ 可空:绝大多数事故不是从工单来的(用户直接报案),不强制关联。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incident') && ! Schema::hasColumn('incident', 'ticket_id')) {
            Schema::table('incident', function (Blueprint $table) {
                $table->unsignedInteger('ticket_id')->nullable()->after('customer_id');
                $table->index('ticket_id', 'idx_incident_ticket');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('incident') && Schema::hasColumn('incident', 'ticket_id')) {
            Schema::table('incident', function (Blueprint $table) {
                $table->dropIndex('idx_incident_ticket');
                $table->dropColumn('ticket_id');
            });
        }
    }
};
