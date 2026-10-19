<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-06 · `ecosystem_media`（ADR-0001 §4.9 / G1-D06）。
 *
 * 一条媒体记录只描述**一份不可变字节**：
 *   · `storage_key` 是私有盘路径（`ecosystem/media/{sha256 前 2 位}/{sha256}.{ext}`），
 *     **永不进入公开 DTO**；
 *   · `sha256` 全局唯一：相同内容不重复落盘，也禁止原位替换文件；
 *   · `status` 默认 `pending`，由审核动作转到 approved / rejected / quarantined；
 *   · `uploaded_by` 只存后台账号 ID，**刻意不建外键**（账号可能归档，审计事实必须保留）。
 *
 * 本表不建任何外键；`ecosystem_media_refs` 与 `event_rule_versions.file_media_id`
 * 的 FK 分别在 000013 / 000014 建立。
 */
return new class extends Migration
{
    private const TABLE = 'ecosystem_media';

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
            // 部分失败后的重跑：表在但列不齐时“缺什么补什么”。
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
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // 000013 的 media 外键、000014 的 file_media 外键必须先由各自迁移撤销；
        // 若这里仍有外键指向本表，删表会失败 —— 让它失败，而不是静默删掉别处迁移的约束。
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * @return array<string, callable(Blueprint): mixed>
     */
    private function columnDefinitions(): array
    {
        return [
            // 私有存储盘标识；不对外。
            'disk'          => fn (Blueprint $table) => $table->string('disk', 50),
            // 私有路径；uk(storage_key)。永不进入公开 DTO。
            'storage_key'   => fn (Blueprint $table) => $table->string('storage_key', 255),
            // 原始文件名；展示前仍需净化，可为 NULL。
            'original_name' => fn (Blueprint $table) => $table->string('original_name', 255)->nullable(),
            // 真实解码后的 MIME（finfo + getimagesize 交叉验证）。
            'mime'          => fn (Blueprint $table) => $table->string('mime', 100),
            'byte_size'     => fn (Blueprint $table) => $table->unsignedBigInteger('byte_size'),
            // 图片尺寸；PDF 为 NULL。
            'width'         => fn (Blueprint $table) => $table->unsignedInteger('width')->nullable(),
            'height'        => fn (Blueprint $table) => $table->unsignedInteger('height')->nullable(),
            // 内容哈希；uk(sha256)，文件不可原位替换。
            'sha256'        => fn (Blueprint $table) => $table->char('sha256', 64),
            // pending / approved / rejected / quarantined。
            'status'        => fn (Blueprint $table) => $table->string('status', 20)->default('pending'),
            // 后台用户 ID；不建外键（见类注释）。
            'uploaded_by'   => fn (Blueprint $table) => $table->unsignedBigInteger('uploaded_by'),

            'created_at'    => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at'    => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];
    }

    /**
     * @return array<string, array{columns: array<int, string>, unique: bool}>
     */
    private function indexDefinitions(): array
    {
        return [
            'uk_ecosystem_media_storage_key' => [
                'columns' => ['storage_key'],
                'unique'  => true,
            ],
            'uk_ecosystem_media_sha256' => [
                'columns' => ['sha256'],
                'unique'  => true,
            ],
            'idx_ecosystem_media_status' => [
                'columns' => ['status'],
                'unique'  => false,
            ],
        ];
    }
};
