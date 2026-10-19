<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-04 · `event_revisions`（ADR-0001 §4.3）。
 *
 * 活动公开内容的**版本化审计证据**：每个 revision 一旦离开 draft 就不可再改内容，
 * 编辑只能创建 `revision_no + 1`。因此本表：
 *   · `event_id` / `revision_no` 唯一，revision 号从 1 递增、不复用；
 *   · `event_id` 外键指向 `events.id`，RESTRICT/RESTRICT —— 有 revision 的活动不可物理删除；
 *   · `organizer_id` 外键指向 `ecosystem_organizers.id`，RESTRICT/RESTRICT ——
 *     被历史版本引用的主体不可物理删除；NULL 表示主体未核定（历史/待补）；
 *   · `created_by` / `submitted_by` / `reviewed_by` **刻意不建外键**：后台账号可能归档，
 *     而审计事实必须保留；调用方存在性由后续服务层校验。
 *
 * 本迁移只建结构与索引/外键，不创建 revision 写入服务，也不回填任何存量活动。
 * 前置表缺失时显式 throw：静默跳过会留下“迁移成功但没有外键”的半成品结构。
 */
return new class extends Migration
{
    private const TABLE = 'event_revisions';

    private const FK_EVENT = 'fk_event_revisions_event_id';

    private const FK_ORGANIZER = 'fk_event_revisions_organizer_id';

    public function up(): void
    {
        $this->assertPrerequisites();

        $definitions = $this->columnDefinitions();

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($definitions): void {
                $table->id();

                foreach ($definitions as $definition) {
                    $definition($table);
                }
            });
        } else {
            // 部分失败后的重跑：表在但列不齐时“缺什么补什么”。
            // `id` 不在 definitions 里 —— 既有表缺主键属于结构性损坏，不自动修补。
            $missingColumns = array_values(array_filter(
                array_keys($definitions),
                fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column)
            ));

            if ($missingColumns !== []) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($definitions, $missingColumns): void {
                    foreach ($missingColumns as $column) {
                        $definitions[$column]($table);
                    }
                });
            }
        }

        foreach ($this->indexDefinitions() as $name => $definition) {
            if (Schema::hasIndex(self::TABLE, $name)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }

        if (! $this->hasForeignKey(self::FK_EVENT)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('event_id', self::FK_EVENT)
                    ->references('id')
                    ->on('events')
                    ->onDelete('restrict')
                    ->onUpdate('restrict');
            });
        }

        if (! $this->hasForeignKey(self::FK_ORGANIZER)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->foreign('organizer_id', self::FK_ORGANIZER)
                    ->references('id')
                    ->on('ecosystem_organizers')
                    ->onDelete('restrict')
                    ->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // down 只回收本迁移自己的对象。迁移回滚顺序保证 000007 先撤销
        // events 上指向本表的两个外键；这里若还存在，说明回滚顺序被破坏，
        // 强行删表会失败 —— 让它失败，而不是静默删掉别处的约束。
        foreach ([self::FK_EVENT, self::FK_ORGANIZER] as $constraint) {
            if ($this->hasForeignKey($constraint)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropForeign($constraint));
            }
        }

        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            'event_id'                      => fn (Blueprint $table) => $table->unsignedBigInteger('event_id'),
            'revision_no'                   => fn (Blueprint $table) => $table->unsignedInteger('revision_no'),

            // 公开内容快照；非 draft 后由模型守卫禁止更新。
            'name'                          => fn (Blueprint $table) => $table->string('name', 255),
            'summary'                       => fn (Blueprint $table) => $table->string('summary', 500)->nullable(),
            'detail_html'                   => fn (Blueprint $table) => $table->mediumText('detail_html')->nullable(),
            'city'                          => fn (Blueprint $table) => $table->string('city', 100)->nullable(),
            'location_text'                 => fn (Blueprint $table) => $table->string('location_text', 255)->nullable(),
            'participation_mode'            => fn (Blueprint $table) => $table->string('participation_mode', 20)->nullable(),
            'timezone'                      => fn (Blueprint $table) => $table->string('timezone', 64),

            // UTC 存储（ADR G1-D04）；待定为 NULL。
            'starts_at'                     => fn (Blueprint $table) => $table->dateTime('starts_at')->nullable(),
            'ends_at'                       => fn (Blueprint $table) => $table->dateTime('ends_at')->nullable(),

            // 来源快照：发布时的主办与来源事实，不随主体/来源改名而漂移。
            'source_type'                   => fn (Blueprint $table) => $table->string('source_type', 20),
            'platform_role'                 => fn (Blueprint $table) => $table->string('platform_role', 20),
            'organizer_id'                  => fn (Blueprint $table) => $table->unsignedBigInteger('organizer_id')->nullable(),
            'organizer_name_snapshot'       => fn (Blueprint $table) => $table->string('organizer_name_snapshot', 191),
            'source_name'                   => fn (Blueprint $table) => $table->string('source_name', 191),
            // 官方来源 / 外部报名链接不建索引：只做展示与人工核对，不参与检索。
            'source_url'                    => fn (Blueprint $table) => $table->string('source_url', 2048)->nullable(),
            'source_verified_at'            => fn (Blueprint $table) => $table->dateTime('source_verified_at')->nullable(),
            'authorization_basis'           => fn (Blueprint $table) => $table->string('authorization_basis', 50)->nullable(),
            // 私有依据只存引用（存储 key / 文档编号），不存文件本体，永不进入公开 DTO。
            'authorization_evidence_ref'    => fn (Blueprint $table) => $table->string('authorization_evidence_ref', 255)->nullable(),
            'external_registration_url'     => fn (Blueprint $table) => $table->string('external_registration_url', 2048)->nullable(),
            'rule_summary'                  => fn (Blueprint $table) => $table->text('rule_summary')->nullable(),

            // 指纹：内容 sha256 / 媒体集合指纹 / 富文本净化版本。
            'content_fingerprint'           => fn (Blueprint $table) => $table->char('content_fingerprint', 64),
            'media_manifest_hash'           => fn (Blueprint $table) => $table->char('media_manifest_hash', 64)->nullable(),
            'sanitizer_version'             => fn (Blueprint $table) => $table->string('sanitizer_version', 20)->nullable(),

            // 审核轴：draft -> in_review -> approved / rejected。
            'review_state'                  => fn (Blueprint $table) => $table->string('review_state', 20)->default('draft'),

            // 后台用户 ID；不建外键（见类注释），也永不公开。
            'submitted_by'                  => fn (Blueprint $table) => $table->unsignedBigInteger('submitted_by')->nullable(),
            'submitted_at'                  => fn (Blueprint $table) => $table->dateTime('submitted_at')->nullable(),
            'reviewed_by'                   => fn (Blueprint $table) => $table->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at'                   => fn (Blueprint $table) => $table->dateTime('reviewed_at')->nullable(),
            'review_note'                   => fn (Blueprint $table) => $table->text('review_note')->nullable(),

            'change_summary'                => fn (Blueprint $table) => $table->string('change_summary', 500),

            // 该版本首次公开时间；NULL = 尚未发布过。
            'published_at'                  => fn (Blueprint $table) => $table->dateTime('published_at')->nullable(),

            'created_by'                    => fn (Blueprint $table) => $table->unsignedBigInteger('created_by'),

            'created_at'                    => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'                    => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'uk_event_revisions_event_revision'    => [
                'columns' => ['event_id', 'revision_no'],
                'unique'  => true,
            ],
            'idx_event_revisions_event_review_state' => [
                'columns' => ['event_id', 'review_state'],
                'unique'  => false,
            ],
            'idx_event_revisions_organizer_id'     => [
                'columns' => ['organizer_id'],
                'unique'  => false,
            ],
        ];
    }

    private function assertPrerequisites(): void
    {
        if (! Schema::hasTable('events')) {
            throw new RuntimeException(
                'events 不存在，无法创建 event_revisions；请先执行 G1-02 的 2026_10_19_000001。'
            );
        }

        if (! Schema::hasTable('ecosystem_organizers')) {
            throw new RuntimeException(
                'ecosystem_organizers 不存在，无法为 event_revisions.organizer_id 建立外键；请先执行 2026_10_19_000003。'
            );
        }
    }

    private function hasForeignKey(string $constraint): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', self::TABLE)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
