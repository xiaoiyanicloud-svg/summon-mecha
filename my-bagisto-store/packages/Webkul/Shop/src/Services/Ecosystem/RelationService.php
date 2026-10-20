<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemRelation;
use Webkul\Shop\Models\Event;

/**
 * G4-03 · 生态关联解析。
 *
 * 关联配置只存引用；**目标是否仍可展示在读取时逐项复核**：
 *   · 活动/资讯必须仍满足公开可见性；
 *   · 机型只认 active / display_only，且明确标注“仅展示”；
 *   · 技能必须有已发布版本；
 *   · 场景必须 status=1。
 *
 * 失效目标整项省略，不输出占位 CTA、不输出旧标题，也不修改设备/商品真相源。
 */
class RelationService
{
    public const SOURCE_EVENT = 'event';

    public const SOURCE_CONTENT = 'content';

    public const TARGET_EVENT = 'event';

    public const TARGET_CONTENT = 'content';

    public const TARGET_DEVICE_MODEL = 'device_model';

    public const TARGET_SKILL = 'skill';

    public const TARGET_SCENE = 'scene';

    public const SOURCE_TYPES = [self::SOURCE_EVENT, self::SOURCE_CONTENT];

    public const TARGET_TYPES = [
        self::TARGET_EVENT,
        self::TARGET_CONTENT,
        self::TARGET_DEVICE_MODEL,
        self::TARGET_SKILL,
        self::TARGET_SCENE,
    ];

    private const GROUP_KEYS = [
        self::TARGET_EVENT       => 'events',
        self::TARGET_CONTENT     => 'contents',
        self::TARGET_DEVICE_MODEL => 'device_models',
        self::TARGET_SKILL       => 'skills',
        self::TARGET_SCENE       => 'scenes',
    ];

    public function __construct(
        private readonly PublicEventQuery $events,
        private readonly PublicContentQuery $contents,
        private readonly PublicReadGate $gate,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ecosystem_g4.relations.enabled', false);
    }

    /**
     * 解析某来源的公开关联。
     *
     * @return array{events: array<int, array<string, mixed>>, contents: array<int, array<string, mixed>>, device_models: array<int, array<string, mixed>>, skills: array<int, array<string, mixed>>, scenes: array<int, array<string, mixed>>}
     */
    public function forSource(string $sourceType, int|string $sourceKey): array
    {
        $result = array_fill_keys(array_values(self::GROUP_KEYS), []);

        if (! $this->enabled()
            || ! in_array($sourceType, self::SOURCE_TYPES, true)
            || ! $this->gate->available()
            || ! $this->domainAvailable($sourceType)) {
            return $result;
        }

        $max = max(1, (int) config('ecosystem_g4.relations.max_per_source', 12));

        $rows = EcosystemRelation::query()
            ->where('source_type', $sourceType)
            ->where('source_key', (string) $sourceKey)
            ->where('status', EcosystemRelation::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(max(100, $max * 10))
            ->get();

        $count = 0;

        foreach ($rows as $row) {
            if ($count >= $max) {
                break;
            }

            $item = $this->resolveTarget((string) $row->target_type, (string) $row->target_key);

            if ($item === null) {
                continue;
            }

            $result[self::GROUP_KEYS[(string) $row->target_type]][] = $item;
            $count++;
        }

        return $result;
    }

    /**
     * 关联不能绕过公开读取闸或域开关。
     */
    private function domainAvailable(string $domain): bool
    {
        return match ($domain) {
            self::SOURCE_EVENT => (bool) config('ecosystem.events.public_enabled', false),
            self::SOURCE_CONTENT => (bool) config('ecosystem.content.public_enabled', false),
            default => false,
        };
    }

    /**
     * Admin 保存关联时校验目标真实存在（不要求当前公开，公开解析会再过滤）。
     */
    public function targetExists(string $targetType, string $targetKey): bool
    {
        if (! in_array($targetType, self::TARGET_TYPES, true)) {
            return false;
        }

        $targetKey = trim($targetKey);

        if ($targetKey === '' || mb_strlen($targetKey) > 191) {
            return false;
        }

        return match ($targetType) {
            self::TARGET_EVENT => ctype_digit($targetKey)
                && DB::table('events')->where('id', (int) $targetKey)->exists(),
            self::TARGET_CONTENT => DB::table('content_posts')->where('slug', $targetKey)->exists(),
            self::TARGET_DEVICE_MODEL => ctype_digit($targetKey)
                && DB::table('device_model')->where('id', (int) $targetKey)->exists(),
            self::TARGET_SKILL => ctype_digit($targetKey)
                && DB::table('products')->where('id', (int) $targetKey)->exists(),
            self::TARGET_SCENE => ctype_digit($targetKey)
                && DB::table('scenes')->where('id', (int) $targetKey)->exists(),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveTarget(string $targetType, string $targetKey): ?array
    {
        return match ($targetType) {
            self::TARGET_EVENT => $this->resolveEvent($targetKey),
            self::TARGET_CONTENT => $this->resolveContent($targetKey),
            self::TARGET_DEVICE_MODEL => $this->resolveDeviceModel($targetKey),
            self::TARGET_SKILL => $this->resolveSkill($targetKey),
            self::TARGET_SCENE => $this->resolveScene($targetKey),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveEvent(string $key): ?array
    {
        if (! $this->domainAvailable(self::SOURCE_EVENT)) {
            return null;
        }

        if (! ctype_digit($key)) {
            return null;
        }

        $event = $this->events->findPublished((int) $key);
        $revision = $event?->publishedRevision;

        if (! $event instanceof Event || ! $revision instanceof \Webkul\Shop\Models\EventRevision) {
            return null;
        }

        return [
            'type'       => self::TARGET_EVENT,
            'id'         => (int) $event->id,
            'name'       => (string) $revision->name,
            'summary'    => $revision->summary !== null ? (string) $revision->summary : null,
            'starts_at'  => $this->iso($revision->starts_at),
            'ends_at'    => $this->iso($revision->ends_at),
            'city'       => $revision->city !== null ? (string) $revision->city : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveContent(string $key): ?array
    {
        if (! $this->domainAvailable(self::SOURCE_CONTENT)) {
            return null;
        }

        $post = $this->contents->findPublishedBySlug($key);
        $revision = $post?->publishedRevision;

        if (! $post instanceof ContentPost || ! $revision instanceof \Webkul\Shop\Models\ContentRevision) {
            return null;
        }

        return [
            'type'    => self::TARGET_CONTENT,
            'slug'    => (string) $post->slug,
            'title'   => (string) $revision->title,
            'summary' => $revision->summary !== null ? (string) $revision->summary : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDeviceModel(string $key): ?array
    {
        if (! ctype_digit($key)) {
            return null;
        }

        $model = DB::table('device_model')
            ->where('id', (int) $key)
            ->whereIn('status', ['active', 'display_only'])
            ->first();

        if ($model === null) {
            return null;
        }

        return [
            'type'         => self::TARGET_DEVICE_MODEL,
            'id'           => (int) $model->id,
            'name'         => (string) $model->model_name,
            'brand'        => (string) $model->brand,
            // 只描述“仅展示”，不做可售承诺；购买状态仍以商品真相源为准。
            'availability' => (string) $model->status,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveSkill(string $key): ?array
    {
        if (! ctype_digit($key)) {
            return null;
        }

        $productId = (int) $key;

        $product = DB::table('products')->where('id', $productId)->first();

        if ($product === null) {
            return null;
        }

        $hasPublishedVersion = DB::table('skill_versions')
            ->where('product_id', $productId)
            ->where('status', 'published')
            ->exists();

        if (! $hasPublishedVersion) {
            return null;
        }

        $name = DB::table('product_flat')
            ->where('product_id', $productId)
            ->orderBy('id')
            ->value('name');

        return [
            'type'       => self::TARGET_SKILL,
            'product_id' => $productId,
            'name'       => $name !== null && $name !== '' ? (string) $name : (string) $product->sku,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveScene(string $key): ?array
    {
        if (! ctype_digit($key)) {
            return null;
        }

        $scene = DB::table('scenes')
            ->where('id', (int) $key)
            ->where('status', 1)
            ->first();

        if ($scene === null) {
            return null;
        }

        return [
            'type'     => self::TARGET_SCENE,
            'id'       => (int) $scene->id,
            'name'     => (string) $scene->name,
            'icon'     => $scene->icon !== null ? (string) $scene->icon : null,
            'category' => $scene->category !== null ? (string) $scene->category : null,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()
            ->timezone((string) config('app.timezone', 'Asia/Shanghai'))
            ->toIso8601String();
    }
}
