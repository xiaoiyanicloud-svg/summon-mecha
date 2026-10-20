<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-02 · `event_registration_attempts`（G2 实施计划 §3.1）。
 *
 * 一个 registration 是“同一活动同一身份”的稳定主体；每次报名/重报产生新的
 * attempt。历史轮次永不覆写：取消/拒绝后重报是 `attempt_no + 1`，旧轮次的
 * 同意快照、联系人密文、规则/隐私版本、处理记录全部保留。
 *
 * 联系人只存密文：
 *   · `contact_name_enc` / `contact_phone_enc` / `note_enc` 用应用密钥加密；
 *   · `contact_phone_hash` 是独立 HMAC，用于查重，不是可穷举的裸散列；
 *   · `contact_phone_last4` 用于遮罩展示；
 *   · 明文只允许在受权解密动作中出现，且必须审计。
 */
return new class extends Migration
{
    private const TABLE = 'event_registration_attempts';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('registration_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedBigInteger('scope_id');
                // customers.id 是 INT UNSIGNED；这里用 BIGINT 预留空间，刻意不建外键
                // （历史主体归档不应阻止报名事实保留）。归属由服务层逐层核验。
                $table->unsignedBigInteger('owner_customer_id');

                $table->unsignedInteger('attempt_no');
                $table->string('status', 20)->default('submitted');
                // submitted / confirmed / rejected / canceled / checked_in

                // 报名时的审核方式快照；后续 scope 改配置不影响旧轮次。
                $table->string('review_mode', 10);

                // 表单/规则/隐私快照（不可在提交后回溯覆盖）。
                $table->unsignedInteger('form_version');
                $table->json('form_snapshot')->nullable();
                $table->unsignedBigInteger('rules_version_id')->nullable();
                $table->char('rules_fingerprint', 64)->nullable();
                $table->string('privacy_version', 50);
                $table->char('privacy_fingerprint', 64);
                $table->dateTime('consent_accepted_at');

                // 联系人密文（长度不截断，故用 TEXT）。
                $table->text('contact_name_enc');
                $table->text('contact_phone_enc');
                $table->char('contact_phone_hash', 64);
                $table->char('contact_phone_last4', 4);
                $table->string('contact_city', 100)->nullable();
                $table->text('note_enc')->nullable();

                $table->string('request_key', 100);

                $table->dateTime('submitted_at');
                $table->dateTime('decided_at')->nullable();
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->string('decision_reason_code', 50)->nullable();
                $table->text('decision_note')->nullable();

                $table->dateTime('canceled_at')->nullable();
                // customer / admin / event_canceled / review_expired
                $table->string('cancel_reason_code', 50)->nullable();
                $table->unsignedBigInteger('canceled_by')->nullable();

                $table->dateTime('checked_in_at')->nullable();
                $table->unsignedBigInteger('checked_in_by')->nullable();
                $table->string('checkin_source', 20)->nullable();

                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();

                $table->unique(['registration_id', 'attempt_no'], 'uk_event_registration_attempts_no');
                $table->index(['scope_id', 'status'], 'idx_event_registration_attempts_scope_status');
                $table->index(['event_id', 'status'], 'idx_event_registration_attempts_event_status');
                $table->index(['owner_customer_id', 'status'], 'idx_event_registration_attempts_owner_status');
                $table->index('contact_phone_hash', 'idx_event_registration_attempts_phone_hash');
                $table->index('request_key', 'idx_event_registration_attempts_request_key');

                $table->foreign('registration_id', 'fk_event_registration_attempts_registration')
                    ->references('id')->on('event_registrations')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('event_id', 'fk_event_registration_attempts_event')
                    ->references('id')->on('events')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('scope_id', 'fk_event_registration_attempts_scope')
                    ->references('id')->on('event_registration_scopes')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('rules_version_id', 'fk_event_registration_attempts_rule_version')
                    ->references('id')->on('event_rule_versions')
                    ->onDelete('restrict')->onUpdate('restrict');
            });

            return;
        }

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
            'registration_id'       => fn (Blueprint $table) => $table->unsignedBigInteger('registration_id'),
            'event_id'              => fn (Blueprint $table) => $table->unsignedBigInteger('event_id'),
            'scope_id'              => fn (Blueprint $table) => $table->unsignedBigInteger('scope_id'),
            'owner_customer_id'     => fn (Blueprint $table) => $table->unsignedBigInteger('owner_customer_id'),
            'attempt_no'            => fn (Blueprint $table) => $table->unsignedInteger('attempt_no'),
            'status'                => fn (Blueprint $table) => $table->string('status', 20)->default('submitted'),
            'review_mode'           => fn (Blueprint $table) => $table->string('review_mode', 10),
            'form_version'          => fn (Blueprint $table) => $table->unsignedInteger('form_version'),
            'form_snapshot'         => fn (Blueprint $table) => $table->json('form_snapshot')->nullable(),
            'rules_version_id'      => fn (Blueprint $table) => $table->unsignedBigInteger('rules_version_id')->nullable(),
            'rules_fingerprint'     => fn (Blueprint $table) => $table->char('rules_fingerprint', 64)->nullable(),
            'privacy_version'       => fn (Blueprint $table) => $table->string('privacy_version', 50),
            'privacy_fingerprint'   => fn (Blueprint $table) => $table->char('privacy_fingerprint', 64),
            'consent_accepted_at'   => fn (Blueprint $table) => $table->dateTime('consent_accepted_at'),
            'contact_name_enc'      => fn (Blueprint $table) => $table->text('contact_name_enc'),
            'contact_phone_enc'     => fn (Blueprint $table) => $table->text('contact_phone_enc'),
            'contact_phone_hash'    => fn (Blueprint $table) => $table->char('contact_phone_hash', 64),
            'contact_phone_last4'   => fn (Blueprint $table) => $table->char('contact_phone_last4', 4),
            'contact_city'          => fn (Blueprint $table) => $table->string('contact_city', 100)->nullable(),
            'note_enc'              => fn (Blueprint $table) => $table->text('note_enc')->nullable(),
            'request_key'           => fn (Blueprint $table) => $table->string('request_key', 100),
            'submitted_at'          => fn (Blueprint $table) => $table->dateTime('submitted_at'),
            'decided_at'            => fn (Blueprint $table) => $table->dateTime('decided_at')->nullable(),
            'decided_by'            => fn (Blueprint $table) => $table->unsignedBigInteger('decided_by')->nullable(),
            'decision_reason_code'  => fn (Blueprint $table) => $table->string('decision_reason_code', 50)->nullable(),
            'decision_note'         => fn (Blueprint $table) => $table->text('decision_note')->nullable(),
            'canceled_at'           => fn (Blueprint $table) => $table->dateTime('canceled_at')->nullable(),
            'cancel_reason_code'    => fn (Blueprint $table) => $table->string('cancel_reason_code', 50)->nullable(),
            'canceled_by'           => fn (Blueprint $table) => $table->unsignedBigInteger('canceled_by')->nullable(),
            'checked_in_at'         => fn (Blueprint $table) => $table->dateTime('checked_in_at')->nullable(),
            'checked_in_by'         => fn (Blueprint $table) => $table->unsignedBigInteger('checked_in_by')->nullable(),
            'checkin_source'        => fn (Blueprint $table) => $table->string('checkin_source', 20)->nullable(),
            'row_version'           => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
            'created_at'            => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'            => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
