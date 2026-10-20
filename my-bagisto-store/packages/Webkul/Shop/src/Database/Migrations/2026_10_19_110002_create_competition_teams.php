<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3-03 · 队伍 / 成员 / 邀请码（G3 实施计划 §4）。
 *
 * 设计要点：
 *   · 队伍绑定 edition + track；同一赛项内队伍名唯一（name_key 规范化）；
 *   · 成员关系一行一条（team_id, customer_id 唯一），用 status 表达
 *     invited/active/declined/left/removed；历史动作由 OperationLogger 留痕；
 *   · 邀请码只存 sha256，一次性、有过期时间；不按手机号搜索客户，避免枚举；
 *   · 外键 RESTRICT：有队伍/成员的记录不可物理删除，避免历史快照悬空。
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertPrerequisites();

        $this->createTeams();
        $this->createMembers();
        $this->createInvites();

        $this->addIndexes('competition_teams', [
            'uk_competition_teams_track_name' => ['columns' => ['track_id', 'name_key'], 'unique' => true],
            'idx_competition_teams_track_status' => ['columns' => ['track_id', 'status'], 'unique' => false],
            'idx_competition_teams_captain'  => ['columns' => ['captain_customer_id'], 'unique' => false],
        ]);

        $this->addIndexes('competition_team_members', [
            'uk_competition_team_members_team_customer' => ['columns' => ['team_id', 'customer_id'], 'unique' => true],
            'idx_competition_team_members_customer_status' => ['columns' => ['customer_id', 'status'], 'unique' => false],
            'idx_competition_team_members_team_status' => ['columns' => ['team_id', 'status'], 'unique' => false],
        ]);

        $this->addIndexes('competition_team_invites', [
            'uk_competition_team_invites_token' => ['columns' => ['token_hash'], 'unique' => true],
            'idx_competition_team_invites_team_status' => ['columns' => ['team_id', 'status'], 'unique' => false],
        ]);

        $this->addForeignKey('competition_teams', 'edition_id', 'fk_competition_teams_edition', 'competition_editions');
        $this->addForeignKey('competition_teams', 'track_id', 'fk_competition_teams_track', 'competition_tracks');
        $this->addForeignKey('competition_teams', 'captain_customer_id', 'fk_competition_teams_captain', 'customers');
        $this->addForeignKey('competition_team_members', 'team_id', 'fk_competition_team_members_team', 'competition_teams');
        $this->addForeignKey('competition_team_members', 'customer_id', 'fk_competition_team_members_customer', 'customers');
        $this->addForeignKey('competition_team_invites', 'team_id', 'fk_competition_team_invites_team', 'competition_teams');
    }

    public function down(): void
    {
        foreach (['competition_team_invites', 'competition_team_members', 'competition_teams'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function assertPrerequisites(): void
    {
        foreach (['competition_editions', 'competition_tracks', 'customers'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(sprintf('G3-03 迁移缺少前置表 %s。', $table));
            }
        }
    }

    private function createTeams(): void
    {
        if (Schema::hasTable('competition_teams')) {
            return;
        }

        Schema::create('competition_teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('edition_id');
            $table->unsignedBigInteger('track_id');
            $table->unsignedInteger('captain_customer_id');
            $table->string('name', 120);
            $table->string('name_key', 64);
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
        });
    }

    private function createMembers(): void
    {
        if (Schema::hasTable('competition_team_members')) {
            return;
        }

        Schema::create('competition_team_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedInteger('customer_id');
            $table->string('role', 10)->default('member');
            $table->string('status', 20)->default('invited');
            $table->unsignedInteger('invited_by_customer_id')->nullable();
            $table->dateTime('invited_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->unsignedInteger('row_version')->default(0);
            $table->timestamps();
        });
    }

    private function createInvites(): void
    {
        if (Schema::hasTable('competition_team_invites')) {
            return;
        }

        Schema::create('competition_team_invites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->char('token_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->dateTime('expires_at');
            $table->unsignedInteger('created_by_customer_id');
            $table->unsignedInteger('accepted_by_customer_id')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->timestamps();
        });
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
