<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;

/**
 * 资讯文章草稿写入口（ADR-0001 §4.6 / G1-D06 / G1-05）。
 *
 * 本批只做**文章壳的创建与编辑**（type / slug / category）：
 *   · 创建：强制 `draft` / `normal` / `row_version=0`，双 revision 指针为 NULL；
 *   · 编辑：只允许 type / slug / category，行锁 + `expected_row_version` CAS；
 *   · 只有 `publish_status=draft` 的文章可编辑；
 *   · `first_published_at` 非空后 slug 不可改（ADR 冻结策略）；
 *   · 字段无实质变化时**不推版本**（no-op 是正常幂等短路，与并发冲突区分）。
 *
 * 本类不写入 revision、来源、审核/发布/outbox；这些属于后续批次与 G1-08。
 */
class ContentPostService
{
    /**
     * 调用方可写的文章壳字段。
     *
     * 标题/正文/作者/来源属于 revision，发布轴/处置轴/指针/审计字段
     * 一律由服务端维护 —— 允许调用方透传等于绕过状态机。
     *
     * @var array<int, string>
     */
    public const EDITABLE_FIELDS = ['type', 'slug', 'category'];

    /**
     * @param  array<string, mixed>  $data  只允许 EDITABLE_FIELDS 中的键
     *
     * @throws ContentSlugTakenException slug 已存在（包括已撤稿但保留的 slug）
     * @throws InvalidArgumentException 缺必填字段、越权字段、非法枚举 / slug
     */
    public function createDraft(int $adminId, array $data): ContentPost
    {
        $this->assertOnlyEditableFields($data, '创建内容草稿');
        $this->assertAdminExists($adminId);

        if (! array_key_exists('type', $data)) {
            throw new InvalidArgumentException('创建内容草稿必须提供 type。');
        }

        if (! array_key_exists('slug', $data)) {
            throw new InvalidArgumentException('创建内容草稿必须提供 slug。');
        }

        $type = $this->assertType($data['type']);
        $slug = $this->assertSlug($data['slug']);
        $category = $this->normalizeCategory($data['category'] ?? null);

        $this->assertSlugAvailable($slug);

        $post = new ContentPost;
        $post->type = $type;
        $post->slug = $slug;
        $post->category = $category;

        // 服务端字段一律钉死，调用方不可透传。
        $post->publish_status = 'draft';
        $post->disposition = 'normal';
        $post->row_version = 0;
        $post->published_revision_id = null;
        $post->working_revision_id = null;
        $post->first_published_at = null;
        $post->published_at = null;
        $post->created_by = $adminId;
        $post->updated_by = $adminId;

        try {
            $post->save();
        } catch (QueryException $exception) {
            // 唯一键是防并发重复的最后一道闸；转成稳定业务码，不把 SQL 错误透出去。
            if ($this->isDuplicateSlug($exception)) {
                throw new ContentSlugTakenException($slug);
            }

            throw $exception;
        }

        return $post;
    }

    /**
     * 只允许改 type / slug / category；必须仍是 draft；row_version 必须匹配。
     *
     * @param  array<string, mixed>  $data  只允许 EDITABLE_FIELDS 中的键
     *
     * @throws ContentRevisionConflictException 调用方持有的 row_version 已过期
     * @throws ContentTransitionException 文章已不是 draft，或首次发布后改 slug
     * @throws ContentSlugTakenException 新 slug 已被其他文章占用
     */
    public function updateDraft(
        ContentPost $post,
        int $expectedRowVersion,
        int $adminId,
        array $data,
    ): ContentPost {
        $this->assertOnlyEditableFields($data, '更新内容草稿');
        $this->assertAdminExists($adminId);

        if ($post->getKey() === null) {
            throw new InvalidArgumentException('更新内容草稿前文章必须已保存。');
        }

        return DB::transaction(function () use ($post, $expectedRowVersion, $adminId, $data): ContentPost {
            $locked = ContentPost::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->publish_status !== 'draft') {
                throw new ContentTransitionException(sprintf(
                    '文章 %s 当前 publish_status=%s，只有 draft 可以编辑；发布/撤稿请走状态机。',
                    (string) $locked->getKey(),
                    (string) $locked->publish_status,
                ));
            }

            $actualRowVersion = (int) $locked->row_version;

            if ($actualRowVersion !== $expectedRowVersion) {
                throw new ContentRevisionConflictException(
                    (int) $locked->getKey(),
                    $expectedRowVersion,
                    $actualRowVersion,
                );
            }

            $changes = $this->resolveChanges($locked, $data);

            // no-op 不推版本、不改 updated_by：这是幂等短路，不是并发冲突。
            if ($changes === []) {
                return $locked;
            }

            if (array_key_exists('slug', $changes)
                && $locked->getOriginal('first_published_at') !== null) {
                throw new ContentTransitionException(sprintf(
                    '文章 %s 已首次发布，slug 不可修改；如需更换公开地址必须走独立迁移策略。',
                    (string) $locked->getKey(),
                ));
            }

            if (array_key_exists('slug', $changes)) {
                $this->assertSlugAvailable((string) $changes['slug'], (int) $locked->getKey());
            }

            $locked->fill($changes);
            $locked->updated_by = $adminId;
            $locked->row_version = $actualRowVersion + 1;

            try {
                $locked->save();
            } catch (QueryException $exception) {
                if ($this->isDuplicateSlug($exception)) {
                    throw new ContentSlugTakenException((string) ($changes['slug'] ?? $locked->slug));
                }

                throw $exception;
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> 只包含实际变化的字段
     */
    private function resolveChanges(ContentPost $post, array $data): array
    {
        $changes = [];

        if (array_key_exists('type', $data)) {
            $type = $this->assertType($data['type']);

            if ($type !== (string) $post->type) {
                $changes['type'] = $type;
            }
        }

        if (array_key_exists('slug', $data)) {
            $slug = $this->assertSlug($data['slug']);

            if ($slug !== (string) $post->slug) {
                $changes['slug'] = $slug;
            }
        }

        if (array_key_exists('category', $data)) {
            $category = $this->normalizeCategory($data['category']);

            if ($category !== $post->category) {
                $changes['category'] = $category;
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyEditableFields(array $data, string $action): void
    {
        $forbidden = array_values(array_diff(array_keys($data), self::EDITABLE_FIELDS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s时不允许传入字段：%s；发布轴、处置轴、双 revision 指针与审计字段只能由服务端维护。',
                $action,
                implode(', ', $forbidden),
            ));
        }
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('内容草稿需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function assertType(mixed $value): string
    {
        $allowed = array_values((array) config('ecosystem_content.content_types', []));

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'type 必须是 %s 之一，收到「%s」。',
                implode(' / ', $allowed),
                is_scalar($value) ? (string) $value : gettype($value),
            ));
        }

        return $value;
    }

    private function assertSlug(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('slug 必须是字符串。');
        }

        $rules = (array) config('ecosystem_content.slug', []);
        $min = (int) ($rules['min_length'] ?? 3);
        $max = (int) ($rules['max_length'] ?? 120);
        $pattern = (string) ($rules['pattern'] ?? '/^[a-z0-9-]+$/');
        $reserved = array_values((array) ($rules['reserved_prefixes'] ?? []));

        $length = strlen($value);

        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(sprintf(
                'slug 长度必须在 %d–%d 之间，收到 %d 个字符。',
                $min,
                $max,
                $length,
            ));
        }

        // 不做小写化 / 拼音转写 / trim：非法输入必须显式拒绝，不能悄悄改写成另一个公开地址。
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(
                'slug 只允许小写 ASCII 字母、数字和连字符（不做自动转写或小写化）。'
            );
        }

        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            throw new InvalidArgumentException('slug 不得以连字符开头或结尾。');
        }

        foreach ($reserved as $prefix) {
            if ($value === $prefix || str_starts_with($value, $prefix.'-')) {
                throw new InvalidArgumentException(sprintf(
                    'slug「%s」使用了保留前缀「%s」，不得占用。',
                    $value,
                    $prefix,
                ));
            }
        }

        return $value;
    }

    private function normalizeCategory(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('category 必须是字符串或 NULL。');
        }

        $category = trim($value);

        if ($category === '') {
            return null;
        }

        // 数据库列是 VARCHAR(64)，按**字符**而非字节计长；中文分类名不能因
        // UTF-8 三字节编码被误判超长（strlen('机器人')=9，mb_strlen=3）。
        if (mb_strlen($category) > 64) {
            throw new InvalidArgumentException('category 不得超过 64 个字符。');
        }

        return $category;
    }

    /**
     * slug 全局唯一，且已撤稿对象的 slug 永久保留（ADR §3 G1-D06）。
     */
    private function assertSlugAvailable(string $slug, ?int $exceptPostId = null): void
    {
        $query = ContentPost::query()->where('slug', $slug);

        if ($exceptPostId !== null) {
            $query->whereKeyNot($exceptPostId);
        }

        $existing = $query->first();

        if ($existing !== null) {
            throw new ContentSlugTakenException($slug, (int) $existing->getKey());
        }
    }

    private function isDuplicateSlug(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'uk_content_posts_slug')
            || str_contains($message, 'Duplicate entry');
    }
}
