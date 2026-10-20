<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-01 · `event_registration_scopes`（ADR-0001 §4.12 / G2 实施计划 §3.1）。
 *
 * 首期每个活动一个 `scope_key=default`。容量三态沿用 ADR G1-D04：
 *   · capacity = NULL 不限；
 *   · capacity = 0    关闭名额（**不是**不限）；
 *   · capacity > 0    有限容量。
 *
 * `capacity_confirmed_at` / `policy_confirmed_at` 是上线闸门：运营没有显式确认
 * 容量与政策时，scope 不能 open。未知容量绝不能被当成不限。
 *
 * 迁移按 hasTable / hasColumn 幂等编写；MySQL 5.7 DDL 不在事务里，失败需重跑。
 */
return new class extends Migration
{
    private const TABLE = 'event_registration_scopes';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('event_id');
                $table->string('scope_key', 50)->default('default');

                // team / person；由 events.kind 决定，首次报名后不可改。
                $table->string('unit', 10);

                // NULL=不限，0=关闭，>0=有限（ADR G1-D04）。
                $table->unsignedInteger('capacity')->nullable();
                $table->dateTime('capacity_confirmed_at')->nullable();

                // 只计当前 confirmed / checked_in；submitted 不占位。
                $table->unsignedInteger('confirmed_count')->default(0);

                // auto：提交即确认并占位；manual：提交为 submitted，批准时才占位。
                $table->string('review_mode', 10);

                // UTC DATETIME（ADR G1-D04）。opens 为空表示立即开放。
                $table->dateTime('registration_opens_at')->nullable();
                $table->dateTime('registration_closes_at')->nullable();
                $table->dateTime('review_closes_at')->nullable();

                // before_close / never；签到后自助取消另由 attempt 状态拒绝。
                $table->string('cancel_policy', 20)->default('before_close');
                // allowed / never。
                $table->string('reapply_policy', 20)->default('allowed');
                // NULL=不限；否则为重报轮次上限（含首次）。
                $table->unsignedTinyInteger('max_attempts')->nullable();

                // 活动变更是否需要报名人显式确认；政策未配置时不得发布需确认变更。
                $table->boolean('change_ack_required')->default(false);
                $table->boolean('decline_releases_seat')->default(false);
                $table->string('no_response_action', 20)->default('none'); // none / release

                // 版本化报名表：字段定义与隐私文本版本不可在已有 attempt 上回溯覆盖。
                $table->unsignedInteger('form_version')->default(1);
                $table->json('form_schema')->nullable();
                $table->string('privacy_version', 50)->nullable();
                $table->text('privacy_summary')->nullable();

                // 报名绑定的规则版本（可选）；存在已批准规则时由服务层强制绑定。
                $table->unsignedBigInteger('rule_version_id')->nullable();

                $table->string('status', 20)->default('draft'); // draft / open / closed

                // 激活证据：谁在何时确认了容量、审核方式、时间窗与取消/重报政策。
                $table->dateTime('policy_confirmed_at')->nullable();
                $table->unsignedBigInteger('policy_confirmed_by')->nullable();

                $table->unsignedInteger('row_version')->default(0);

                $table->timestamps();

                $table->unique(['event_id', 'scope_key'], 'uk_event_registration_scopes_event_key');
                $table->index('event_id', 'idx_event_registration_scopes_event');
                $table->index(['event_id', 'status'], 'idx_event_registration_scopes_event_status');
                $table->foreign('event_id', 'fk_event_registration_scopes_event')
                    ->references('id')->on('events')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('rule_version_id', 'fk_event_registration_scopes_rule_version')
                    ->references('id')->on('event_rule_versions')
                    ->onDelete('restrict')->onUpdate('restrict');
            });

            return;
        }

        // 部分失败后的重跑：缺列补齐（id / 外键 / 索引不自动修补）。
        $definitions = $this->columnDefinitions();
        $missing = array_values(array_filter(
            array_keys($definitions),
            fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
        ));

        if ($missing !== []) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($definitions, $missing): void {
                foreach ($missing as $column) {
                    $definitions[$column]($table);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'event_id'               => fn (Blueprint $table) => $table->unsignedBigInteger('event_id'),
            'scope_key'              => fn (Blueprint $table) => $table->string('scope_key', 50)->default('default'),
            'unit'                   => fn (Blueprint $table) => $table->string('unit', 10),
            'capacity'               => fn (Blueprint $table) => $table->unsignedInteger('capacity')->nullable(),
            'capacity_confirmed_at'  => fn (Blueprint $table) => $table->dateTime('capacity_confirmed_at')->nullable(),
            'confirmed_count'        => fn (Blueprint $table) => $table->unsignedInteger('confirmed_count')->default(0),
            'review_mode'            => fn (Blueprint $table) => $table->string('review_mode', 10),
            'registration_opens_at'  => fn (Blueprint $table) => $table->dateTime('registration_opens_at')->nullable(),
            'registration_closes_at' => fn (Blueprint $table) => $table->dateTime('registration_closes_at')->nullable(),
            'review_closes_at'       => fn (Blueprint $table) => $table->dateTime('review_closes_at')->nullable(),
            'cancel_policy'          => fn (Blueprint $table) => $table->string('cancel_policy', 20)->default('before_close'),
            'reapply_policy'         => fn (Blueprint $table) => $table->string('reapply_policy', 20)->default('allowed'),
            'max_attempts'           => fn (Blueprint $table) => $table->unsignedTinyInteger('max_attempts')->nullable(),
            'change_ack_required'    => fn (Blueprint $table) => $table->boolean('change_ack_required')->default(false),
            'decline_releases_seat'  => fn (Blueprint $table) => $table->boolean('decline_releases_seat')->default(false),
            'no_response_action'     => fn (Blueprint $table) => $table->string('no_response_action', 20)->default('none'),
            'form_version'           => fn (Blueprint $table) => $table->unsignedInteger('form_version')->default(1),
            'form_schema'            => fn (Blueprint $table) => $table->json('form_schema')->nullable(),
            'privacy_version'        => fn (Blueprint $table) => $table->string('privacy_version', 50)->nullable(),
            'privacy_summary'        => fn (Blueprint $table) => $table->text('privacy_summary')->nullable(),
            'rule_version_id'        => fn (Blueprint $table) => $table->unsignedBigInteger('rule_version_id')->nullable(),
            'status'                 => fn (Blueprint $table) => $table->string('status', 20)->default('draft'),
            'policy_confirmed_at'    => fn (Blueprint $table) => $table->dateTime('policy_confirmed_at')->nullable(),
            'policy_confirmed_by'    => fn (Blueprint $table) => $table->unsignedBigInteger('policy_confirmed_by')->nullable(),
            'row_version'            => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
            'created_at'             => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'             => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
