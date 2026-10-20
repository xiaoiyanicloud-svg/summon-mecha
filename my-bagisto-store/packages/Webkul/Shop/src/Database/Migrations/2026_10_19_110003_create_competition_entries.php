<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-04 · 参赛条目（当届成员/资格/机器人声明冻结快照）。
 *
 * 一条 entry 对应“某队伍在某赛项的一届参赛事实”：
 *   · unique(team_id) —— 一个队伍在同一届/赛项只有一条参赛条目；
 *   · registration_id / attempt_id 可空（报名可先于资格确认），一旦写入不可改；
 *   · roster_snapshot / eligibility_snapshot / robot_declaration 是提交时的快照，
 *     后续队伍改名/换人不回溯覆盖；
 *   · rules_fingerprint 钉住确认时适用的规则版本。
 *
 * 同时给 event_registrations 增加 team_id（可空）：队伍报名的 subject 是队伍，
 * 历史个人报名保持 team_id=NULL，不受影响。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('competition_teams') || ! Schema::hasTable('events')) {
            throw new RuntimeException('G3-04 迁移缺少 competition_teams / events 前置表。');
        }

        if (! Schema::hasTable('competition_entries')) {
            Schema::create('competition_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('entry_no', 32);
                $table->unsignedBigInteger('edition_id');
                $table->unsignedBigInteger('track_id');
                $table->unsignedBigInteger('team_id');
                $table->unsignedBigInteger('registration_id')->nullable();
                $table->unsignedBigInteger('attempt_id')->nullable();
                $table->unsignedInteger('captain_customer_id');
                $table->string('status', 20)->default('draft');
                $table->json('roster_snapshot')->nullable();
                $table->json('eligibility_snapshot')->nullable();
                $table->json('robot_declaration')->nullable();
                $table->unsignedBigInteger('rules_version_id')->nullable();
                $table->char('rules_fingerprint', 64)->nullable();
                $table->dateTime('frozen_at')->nullable();
                $table->unsignedInteger('frozen_by_customer_id')->nullable();
                $table->unsignedInteger('row_version')->default(0);
                $table->timestamps();
            });
        }

        $this->addIndexes('competition_entries', [
            'uk_competition_entries_no'       => ['columns' => ['entry_no'], 'unique' => true],
            'uk_competition_entries_team'     => ['columns' => ['team_id'], 'unique' => true],
            'uk_competition_entries_registration' => ['columns' => ['registration_id'], 'unique' => true],
            'uk_competition_entries_attempt'  => ['columns' => ['attempt_id'], 'unique' => true],
            'idx_competition_entries_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_entries', 'edition_id', 'fk_competition_entries_edition', 'competition_editions');
        $this->addForeignKey('competition_entries', 'track_id', 'fk_competition_entries_track', 'competition_tracks');
        $this->addForeignKey('competition_entries', 'team_id', 'fk_competition_entries_team', 'competition_teams');
        $this->addForeignKey('competition_entries', 'registration_id', 'fk_competition_entries_registration', 'event_registrations');
        $this->addForeignKey('competition_entries', 'attempt_id', 'fk_competition_entries_attempt', 'event_registration_attempts');
        $this->addForeignKey('competition_entries', 'captain_customer_id', 'fk_competition_entries_captain', 'customers');
        $this->addForeignKey('competition_entries', 'rules_version_id', 'fk_competition_entries_rules', 'event_rule_versions');

        if (! Schema::hasColumn('event_registrations', 'team_id')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->unsignedBigInteger('team_id')->nullable()->after('scope_id');
            });
        }

        if (! Schema::hasIndex('event_registrations', 'idx_event_registrations_team')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->index('team_id', 'idx_event_registrations_team');
            });
        }

        if (! $this->hasForeignKey('event_registrations', 'fk_event_registrations_team')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->foreign('team_id', 'fk_event_registrations_team')
                    ->references('id')->on('competition_teams')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('event_registrations') && $this->hasForeignKey('event_registrations', 'fk_event_registrations_team')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->dropForeign('fk_event_registrations_team');
            });
        }

        if (Schema::hasTable('event_registrations') && Schema::hasColumn('event_registrations', 'team_id')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->dropColumn('team_id');
            });
        }

        Schema::dropIfExists('competition_entries');
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
