<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-03 · `ecosystem_organizers`（ADR-0001 §4.4 / G1-D05）。
 *
 * 主体名称、平台角色、来源与核验信息的事实源。约束边界：
 *   · `platform_role` 一期一主体一个角色，变更需重新核验（服务层负责回 pending）；
 *   · `private_evidence_ref` / `verification_note` 是内部资料，永不进入公开 DTO；
 *   · `verified_by` 指向后台用户 `admins.id`，**刻意不建外键** —— 后台账号可能归档，
 *     而核验事实必须保留；核验人存在性由 OrganizerService 在写入时校验。
 *
 * `name(64)` 是前缀索引：Blueprint 不支持前缀长度，只能走原生 ALTER。
 * 迁移按 hasTable / hasIndex 幂等编写，便于部分失败后重跑；MySQL 5.7 DDL 不在事务里，
 * 失败不会整体回滚，迁移前仍需按 §11.2 备份。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_organizers';

    public function up(): void
    {
        $definitions = $this->columnDefinitions();

        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) use ($definitions): void {
                $table->id();

                foreach ($definitions as $definition) {
                    $definition($table);
                }
            });
        } else {
            // 部分失败后的重跑：表在但列不齐时按“缺什么补什么”补齐。
            // `id` 不在 definitions 里 —— 既有表缺主键属于结构性损坏，不做自动修补。
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

        if (! Schema::hasIndex(self::TABLE, 'idx_ecosystem_organizers_name')) {
            // 走 Schema 的同一连接，避免 `migrate --database=...` 时落错库。
            Schema::getConnection()->statement(
                'ALTER TABLE `ecosystem_organizers`'
                .' ADD INDEX `idx_ecosystem_organizers_name` (`name`(64))'
            );
        }

        if (! Schema::hasIndex(self::TABLE, 'idx_ecosystem_organizers_status_role')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['status', 'platform_role'],
                    'idx_ecosystem_organizers_status_role'
                );
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'idx_ecosystem_organizers_status')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('status', 'idx_ecosystem_organizers_status');
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
            'name'                 => fn (Blueprint $table) => $table->string('name', 191),
            'platform_role'        => fn (Blueprint $table) => $table->string('platform_role', 20),

            'source_url'           => fn (Blueprint $table) => $table->string('source_url', 2048)->nullable(),
            'public_materials'     => fn (Blueprint $table) => $table->text('public_materials')->nullable(),

            // 私有依据只存引用（存储 key / 文档编号），不存文件本体。
            'private_evidence_ref' => fn (Blueprint $table) => $table->string('private_evidence_ref', 255)->nullable(),

            // 核验人 + 核验时间（UTC）；无常驻账号外键，见类注释。
            'verified_by'          => fn (Blueprint $table) => $table->unsignedBigInteger('verified_by')->nullable(),
            'verified_at'          => fn (Blueprint $table) => $table->dateTime('verified_at')->nullable(),

            'status'               => fn (Blueprint $table) => $table->string('status', 20)->default('pending'),
            'verification_note'    => fn (Blueprint $table) => $table->text('verification_note')->nullable(),

            'created_at'           => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'           => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }
};
