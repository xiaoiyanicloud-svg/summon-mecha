<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-02 · 系列 / 届次 / 赛项（G3 实施计划 §3）。
 *
 * 关系：
 *   competition_series 1..n competition_editions 1..n competition_tracks
 *   每个 edition 绑定一个 events 行（活动壳）；每个 track 绑定一个
 *   event_registration_scopes 行（报名容量/审核/时间窗仍由 G2 管理）。
 *
 * 关键约束：
 *   · 一个 event 只能是一届赛事（unique event_id），不允许把同一活动挂到两届；
 *   · track 的 scope 唯一，且服务层校验 scope.event_id === edition.event_id；
 *   · 规则版本只允许引用本活动的 event_rule_versions（服务层校验，FK 只保证存在）；
 *   · 规则 profile / 指纹由 CompetitionRuleProfile 计算，本表不写默认策略。
 *
 * 迁移幂等：表/列/索引/外键分别补齐；前置表缺失时显式 throw。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertPrerequisites();

        $this->createTable('competition_series', $this->seriesColumns());
        $this->createTable('competition_editions', $this->editionColumns());
        $this->createTable('competition_tracks', $this->trackColumns());

        $this->addIndexes('competition_series', [
            'uk_competition_series_slug'       => ['columns' => ['slug'], 'unique' => true],
            'idx_competition_series_organizer' => ['columns' => ['organizer_id'], 'unique' => false],
            'idx_competition_series_status'    => ['columns' => ['status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_editions', [
            'uk_competition_editions_series_no' => ['columns' => ['series_id', 'edition_no'], 'unique' => true],
            'uk_competition_editions_event'     => ['columns' => ['event_id'], 'unique' => true],
            'idx_competition_editions_status'   => ['columns' => ['series_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_tracks', [
            'uk_competition_tracks_edition_key' => ['columns' => ['edition_id', 'track_key'], 'unique' => true],
            'uk_competition_tracks_scope'       => ['columns' => ['registration_scope_id'], 'unique' => true],
            'idx_competition_tracks_status'     => ['columns' => ['edition_id', 'status'], 'unique' => false],
            'idx_competition_tracks_rules'      => ['columns' => ['rules_version_id'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_series', 'organizer_id', 'fk_competition_series_organizer', 'ecosystem_organizers');
        $this->addForeignKey('competition_editions', 'series_id', 'fk_competition_editions_series', 'competition_series');
        $this->addForeignKey('competition_editions', 'event_id', 'fk_competition_editions_event', 'events');
        $this->addForeignKey('competition_tracks', 'edition_id', 'fk_competition_tracks_edition', 'competition_editions');
        $this->addForeignKey('competition_tracks', 'registration_scope_id', 'fk_competition_tracks_scope', 'event_registration_scopes');
        $this->addForeignKey('competition_tracks', 'rules_version_id', 'fk_competition_tracks_rules', 'event_rule_versions');
    }

    public function down(): void
    {
        // 依赖方向：tracks -> editions -> series。只删除本迁移创建的三张表。
        foreach (['competition_tracks', 'competition_editions', 'competition_series'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function assertPrerequisites(): void
    {
        foreach (['events', 'ecosystem_organizers', 'event_rule_versions', 'event_registration_scopes'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(sprintf(
                    'G3-02 迁移缺少前置表 %s；请先执行 G1/G2 迁移。',
                    $table,
                ));
            }
        }
    }

    /**
     * @param  array<string, callable(Blueprint): mixed>  $definitions
     */
    private function createTable(string $tableName, array $definitions): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) use ($definitions): void {
                $table->id();

                foreach ($definitions as $definition) {
                    $definition($table);
                }

                $table->timestamps();
            });

            return;
        }

        $missing = array_values(array_filter(
            array_keys($definitions),
            fn (string $column): bool => ! Schema::hasColumn($tableName, $column),
        ));

        if ($missing !== []) {
            Schema::table($tableName, function (Blueprint $table) use ($definitions, $missing): void {
                foreach ($missing as $column) {
                    $definitions[$column]($table);
                }
            });
        }
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function seriesColumns(): array
    {
        return [
            'organizer_id'  => fn (Blueprint $table) => $table->unsignedBigInteger('organizer_id')->nullable(),
            'organizer_name'=> fn (Blueprint $table) => $table->string('organizer_name', 120),
            'name'          => fn (Blueprint $table) => $table->string('name', 120),
            'name_key'      => fn (Blueprint $table) => $table->string('name_key', 64),
            'slug'          => fn (Blueprint $table) => $table->string('slug', 100),
            'description'   => fn (Blueprint $table) => $table->text('description')->nullable(),
            'status'        => fn (Blueprint $table) => $table->string('status', 20)->default('draft'),
            'row_version'   => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
        ];
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function editionColumns(): array
    {
        return [
            'series_id'    => fn (Blueprint $table) => $table->unsignedBigInteger('series_id'),
            'event_id'     => fn (Blueprint $table) => $table->unsignedBigInteger('event_id'),
            'edition_no'   => fn (Blueprint $table) => $table->unsignedInteger('edition_no'),
            'name'         => fn (Blueprint $table) => $table->string('name', 120),
            'starts_at'    => fn (Blueprint $table) => $table->dateTime('starts_at')->nullable(),
            'ends_at'      => fn (Blueprint $table) => $table->dateTime('ends_at')->nullable(),
            'timezone'     => fn (Blueprint $table) => $table->string('timezone', 64)->default('Asia/Shanghai'),
            'status'       => fn (Blueprint $table) => $table->string('status', 20)->default('draft'),
            'row_version'  => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
        ];
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function trackColumns(): array
    {
        return [
            'edition_id'              => fn (Blueprint $table) => $table->unsignedBigInteger('edition_id'),
            'track_key'               => fn (Blueprint $table) => $table->string('track_key', 50),
            'name'                    => fn (Blueprint $table) => $table->string('name', 120),
            'discipline'              => fn (Blueprint $table) => $table->string('discipline', 64)->nullable(),
            'summary'                 => fn (Blueprint $table) => $table->text('summary')->nullable(),
            'registration_scope_id'   => fn (Blueprint $table) => $table->unsignedBigInteger('registration_scope_id')->nullable(),
            'rules_version_id'        => fn (Blueprint $table) => $table->unsignedBigInteger('rules_version_id')->nullable(),
            'status'                  => fn (Blueprint $table) => $table->string('status', 20)->default('draft'),
            'rules_profile'           => fn (Blueprint $table) => $table->json('rules_profile')->nullable(),
            'rules_fingerprint'       => fn (Blueprint $table) => $table->char('rules_fingerprint', 64)->nullable(),
            'rules_confirmed_at'      => fn (Blueprint $table) => $table->dateTime('rules_confirmed_at')->nullable(),
            'rules_confirmed_by'      => fn (Blueprint $table) => $table->unsignedBigInteger('rules_confirmed_by')->nullable(),
            'min_team_size'           => fn (Blueprint $table) => $table->unsignedTinyInteger('min_team_size')->nullable(),
            'max_team_size'           => fn (Blueprint $table) => $table->unsignedTinyInteger('max_team_size')->nullable(),
            'allow_multi_team'        => fn (Blueprint $table) => $table->boolean('allow_multi_team')->default(false),
            'submission_deadline_at'  => fn (Blueprint $table) => $table->dateTime('submission_deadline_at')->nullable(),
            'allow_correction'        => fn (Blueprint $table) => $table->boolean('allow_correction')->default(true),
            'row_version'             => fn (Blueprint $table) => $table->unsignedInteger('row_version')->default(0),
        ];
    }

    /**
     * @param  array<string, array{columns: array<int, string>, unique: bool}>  $indexes
     */
    private function addIndexes(string $tableName, array $indexes): void
    {
        foreach ($indexes as $name => $definition) {
            if (Schema::hasIndex($tableName, $name)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }
    }

    private function addForeignKey(string $tableName, string $column, string $name, string $references): void
    {
        if ($this->hasForeignKey($tableName, $name)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $name, $references): void {
            $table->foreign($column, $name)
                ->references('id')->on($references)
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    private function hasForeignKey(string $tableName, string $name): bool
    {
        $connection = Schema::getConnection();

        return $connection->table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $connection->getDatabaseName())
            ->where('TABLE_NAME', $tableName)
            ->where('CONSTRAINT_NAME', $name)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }
};
