<?php

namespace Webkul\Shop\Services\Ecosystem;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\ContentSource;

/**
 * 资讯修订草稿写入口（ADR-0001 §4.7 / §4.8 / G1-D06 / G1-05）。
 *
 * 本批只做 **revision 与来源的草稿写入**：
 *   · 创建：在文章行锁内取 `revision_no = max + 1`；指纹由服务端计算，
 *     且把有序来源一并纳入（改正文或改来源都必须产生新指纹）；
 *   · 编辑：只允许内容字段，锁 revision、draft-only、`expectedFingerprint` CAS；
 *     `sources !== null` 时整组替换（draft 守卫下先删旧、再新增）；
 *   · 审核 / 发布 / 指针切换 / outbox 属于 G1-08，本类不实现。
 *
 * 为什么指纹必须服务端算：
 *   调用方传指纹等于允许它“声明内容没变”，审核与发布前的指纹核验就失去意义。
 *   同理，`content_fingerprint`、`revision_no`、审核字段与创建人一律拒绝透传。
 *
 * 本批边界：
 *   · `media_manifest_hash` 强制 NULL（媒体留 G1-06）；
 *   · `sanitizer_version` 强制 `ecosystem_content.sanitizer_placeholder_version`
 *     （真正的净化留 G1-07，渲染端必须能看见“尚未净化”）；
 *   · draft 允许 0 条来源；“in_review 起至少一条且 original 需要 canonical URL”
 *     的提交前置留 G1-08。
 */
class ContentRevisionService
{
    /**
     * 调用方可写的修订内容字段（与 §4.7 内容列一致，排除指纹与审核字段）。
     *
     * `media_manifest_hash` / `sanitizer_version` 之所以保留在表内，是为了让
     * 误传时能给出明确拒绝，而不是被静默丢弃；最终值仍由服务端钉死。
     *
     * @var array<int, string>
     */
    public const CONTENT_FIELDS = [
        'title',
        'summary',
        'body_html',
        'author_name',
        'author_org',
        'source_type',
        'original_published_at',
        'correction_note',
        'change_summary',
        'media_manifest_hash',
        'sanitizer_version',
    ];

    /**
     * @var array<int, string>
     */
    private const REQUIRED_TEXT_FIELDS = ['title', 'author_name', 'source_type', 'change_summary'];

    /**
     * 来源输入允许的键。`sort_order` 接受但会被服务端按输入顺序重排；
     * `id` / `revision_id` / `created_at` 属于数据库审计字段，不得透传。
     *
     * @var array<int, string>
     */
    private const SOURCE_INPUT_FIELDS = [
        'source_name',
        'source_url',
        'source_verified_at',
        'rights_basis',
        'rights_scope',
        'evidence_ref',
        'sort_order',
    ];

    public function __construct(
        private readonly EventSourceValidator $sourceValidator,
        private readonly ContentFingerprint $fingerprints,
    ) {}

    /**
     * 创建一条 draft revision 及其来源。
     *
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     * @param  array<int, array<string, mixed>>  $sources  服务端按输入顺序重排 sort_order
     *
     * @throws InvalidArgumentException 缺必填字段、越权字段、非法枚举 / 文本
     * @throws ContentSourceValidationException 来源缺字段、权利依据不匹配或 URL 非法
     */
    public function createDraft(ContentPost $post, int $adminId, array $data, array $sources = []): ContentRevision
    {
        $this->assertOnlyContentFields($data, '创建内容修订');
        $this->assertAdminExists($adminId);

        if ($post->getKey() === null) {
            throw new InvalidArgumentException('创建内容修订前必须先保存文章。');
        }

        return DB::transaction(function () use ($post, $adminId, $data, $sources): ContentRevision {
            $lockedPost = ContentPost::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attributes = $this->normalizeContentAttributes($data);

            $revision = new ContentRevision;
            $revision->fill($attributes);

            // 服务端字段一律覆盖调用方输入（即使没传，也显式钉死）。
            $revision->post_id = (int) $lockedPost->getKey();
            $revision->revision_no = $this->nextRevisionNo($lockedPost);
            $revision->created_by = $adminId;
            $revision->review_state = 'draft';
            $revision->media_manifest_hash = null;
            $revision->sanitizer_version = $this->sanitizerVersion();

            // 先确认修订自身合法（含 source_type 枚举与 link_summary 正文禁令），
            // 再按该 source_type 校验来源。
            $this->assertContent($revision);

            $normalizedSources = $this->normalizeSources($sources, (string) $revision->source_type);

            $revision->content_fingerprint = $this->fingerprints->contentRevision($revision, $normalizedSources);
            $revision->save();

            $created = $this->replaceSources($revision, $normalizedSources);
            $revision->setRelation('sources', collect($created));

            return $revision;
        });
    }

    /**
     * 只允许改内容字段；必须仍是 draft；指纹不符即冲突。
     *
     * `$sources === null` 表示不动来源；传数组（包括空数组）表示整组替换。
     *
     * @param  array<string, mixed>  $data  只允许 CONTENT_FIELDS 中的键
     * @param  array<int, array<string, mixed>>|null  $sources
     *
     * @throws ContentTransitionException 修订已离开 draft
     * @throws ContentRevisionFingerprintConflictException 调用方基于的指纹已过期
     */
    public function updateDraft(
        ContentRevision $revision,
        string $expectedFingerprint,
        array $data,
        ?array $sources = null,
    ): ContentRevision {
        $this->assertOnlyContentFields($data, '更新内容修订');

        if ($revision->getKey() === null) {
            throw new InvalidArgumentException('更新内容修订前 revision 必须已保存。');
        }

        return DB::transaction(function () use ($revision, $expectedFingerprint, $data, $sources): ContentRevision {
            $locked = ContentRevision::query()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $locked->review_state !== 'draft') {
                throw new ContentTransitionException(sprintf(
                    '内容修订 %s 当前 review_state=%s，只有 draft 可以编辑；请创建 revision_no + 1。',
                    (string) $locked->getKey(),
                    (string) $locked->review_state,
                ));
            }

            $actualFingerprint = (string) $locked->content_fingerprint;

            if (! hash_equals($actualFingerprint, $expectedFingerprint)) {
                throw new ContentRevisionFingerprintConflictException(
                    (int) $locked->getKey(),
                    $expectedFingerprint,
                    $actualFingerprint,
                );
            }

            $attributes = $this->normalizeContentAttributes($data);

            // 在副本上校验“合并后的完整内容”，避免半套更新落库；
            // clone 不触发模型 updating 守卫，守卫仍由下面的 save() 兜底。
            $candidate = clone $locked;
            $candidate->fill($attributes);
            $candidate->media_manifest_hash = null;
            $candidate->sanitizer_version = $this->sanitizerVersion();

            $this->assertContent($candidate);

            // 不动来源时也要按“合并后的 source_type”重校验现有来源：
            // 改了 source_type 却沿用旧 rights_basis 属于非法组合。
            $effectiveSources = $sources === null
                ? $this->existingSources($locked, (string) $candidate->source_type)
                : $this->normalizeSources($sources, (string) $candidate->source_type);

            $candidate->content_fingerprint = $this->fingerprints->contentRevision($candidate, $effectiveSources);

            $locked->fill($attributes);
            $locked->media_manifest_hash = null;
            $locked->sanitizer_version = $this->sanitizerVersion();
            $locked->content_fingerprint = (string) $candidate->content_fingerprint;
            $locked->save();

            if ($sources !== null) {
                $created = $this->replaceSources($locked, $effectiveSources);
                $locked->setRelation('sources', collect($created));
            } else {
                // 关系可能已被调用方加载过；丢弃旧缓存，避免返回过期来源。
                $locked->unsetRelation('sources');
            }

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyContentFields(array $data, string $action): void
    {
        $forbidden = array_values(array_diff(array_keys($data), self::CONTENT_FIELDS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '%s时不允许传入字段：%s；指纹、版本号、审核与创建人字段只能由服务端维护。',
                $action,
                implode(', ', $forbidden),
            ));
        }
    }

    private function assertAdminExists(int $adminId): void
    {
        if ($adminId <= 0 || ! DB::table('admins')->where('id', $adminId)->exists()) {
            throw new InvalidArgumentException(sprintf('内容修订需要存在的后台账号，admin_id=%d 无效。', $adminId));
        }
    }

    private function nextRevisionNo(ContentPost $post): int
    {
        $max = ContentRevision::query()
            ->where('post_id', $post->getKey())
            ->max('revision_no');

        return ($max === null ? 0 : (int) $max) + 1;
    }

    private function sanitizerVersion(): string
    {
        return (string) config('ecosystem_content.sanitizer_placeholder_version');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeContentAttributes(array $data): array
    {
        $attributes = [];

        foreach ($data as $field => $value) {
            if ($field === 'original_published_at') {
                $attributes[$field] = $this->normalizeOriginalPublishedAt($value);

                continue;
            }

            if ($field === 'media_manifest_hash') {
                if ($value !== null) {
                    throw new InvalidArgumentException('本批不写媒体，media_manifest_hash 必须为 NULL（G1-06）。');
                }

                $attributes[$field] = null;

                continue;
            }

            if ($field === 'sanitizer_version') {
                $placeholder = $this->sanitizerVersion();

                if ($value !== null && $value !== $placeholder) {
                    throw new InvalidArgumentException(sprintf(
                        '本批不写富文本净化，sanitizer_version 只能是服务端占位值「%s」（G1-07）。',
                        $placeholder,
                    ));
                }

                $attributes[$field] = $placeholder;

                continue;
            }

            if ($field === 'body_html') {
                $attributes[$field] = $this->normalizeOptionalText($value, $field);

                continue;
            }

            if ($field === 'source_type') {
                $attributes[$field] = $this->normalizeRequiredText($value, $field, 20);

                continue;
            }

            $maxLength = match ($field) {
                'title'          => 255,
                'author_name'    => 191,
                'change_summary' => 500,
                'summary'        => 500,
                'author_org'     => 191,
                'correction_note' => null,
                default          => null,
            };

            $attributes[$field] = in_array($field, self::REQUIRED_TEXT_FIELDS, true)
                ? $this->normalizeRequiredText($value, $field, (int) $maxLength)
                : $this->normalizeOptionalText($value, $field, $maxLength);
        }

        return $attributes;
    }

    private function normalizeRequiredText(mixed $value, string $field, int $maxLength): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('字段 %s 必须是字符串。', $field));
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('内容修订缺少必填字段 %s。', $field));
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('字段 %s 不得超过 %d 个字符。', $field, $maxLength));
        }

        return $value;
    }

    private function normalizeOptionalText(mixed $value, string $field, ?int $maxLength = null): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('字段 %s 必须是字符串或 NULL。', $field));
        }

        if (trim($value) === '') {
            return null;
        }

        if ($maxLength !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(sprintf('字段 %s 不得超过 %d 个字符。', $field, $maxLength));
        }

        return $value;
    }

    private function normalizeOriginalPublishedAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::createFromInterface($value)->utc();
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('字段 original_published_at 只接受时间字符串或日期对象。');
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf('字段 original_published_at 的时间「%s」无法解析。', $value));
        }
    }

    /**
     * 修订自身的内容边界。
     */
    private function assertContent(ContentRevision $revision): void
    {
        foreach (self::REQUIRED_TEXT_FIELDS as $field) {
            $value = $revision->getAttribute($field);

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('内容修订缺少必填字段 %s。', $field));
            }
        }

        $sourceType = (string) $revision->source_type;
        $allowed = array_values((array) config('ecosystem_content.source_types', []));

        if (! in_array($sourceType, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('未知的 source_type「%s」。', $sourceType));
        }

        // 链接摘要只做摘要与必要引用：正文必须为空，不能借摘要通道复制全文。
        if ($sourceType === 'link_summary' && $revision->getAttribute('body_html') !== null) {
            throw ContentSourceValidationException::required(
                'source_type=link_summary 时 body_html 必须为 NULL；链接摘要不得复制全文。'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSources(array $sources, string $sourceType): array
    {
        $normalized = [];

        // 服务端按输入顺序重排 0..n-1，不信任调用方传来的 sort_order。
        foreach (array_values($sources) as $index => $source) {
            if (! is_array($source)) {
                throw new InvalidArgumentException(sprintf('第 %d 条来源必须是数组。', $index));
            }

            $forbidden = array_values(array_diff(array_keys($source), self::SOURCE_INPUT_FIELDS));

            if ($forbidden !== []) {
                throw new InvalidArgumentException(sprintf(
                    '第 %d 条来源不允许传入字段：%s；id / revision_id / created_at 等审计字段只能由服务端维护。',
                    $index,
                    implode(', ', $forbidden),
                ));
            }

            $item = $this->normalizeSource($source, $sourceType);
            $item['sort_order'] = $index;
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * 读取库内现有来源并按合并后的 source_type 重新校验。
     *
     * @return array<int, array<string, mixed>>
     */
    private function existingSources(ContentRevision $revision, string $sourceType): array
    {
        $result = [];

        foreach ($revision->sources()->orderBy('sort_order')->get() as $source) {
            $item = $this->normalizeSource([
                'source_name'        => $source->source_name,
                'source_url'         => $source->source_url,
                'source_verified_at' => $source->source_verified_at,
                'rights_basis'       => $source->rights_basis,
                'rights_scope'       => $source->rights_scope,
                'evidence_ref'       => $source->evidence_ref,
            ], $sourceType);

            $item['sort_order'] = (int) $source->sort_order;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function normalizeSource(array $source, string $sourceType): array
    {
        $name = $source['source_name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw ContentSourceValidationException::required('每条来源都必须有 source_name。');
        }

        if (mb_strlen($name) > 191) {
            throw ContentSourceValidationException::required('source_name 不得超过 191 个字符。');
        }

        $verifiedAt = $this->normalizeSourceVerifiedAt($source['source_verified_at'] ?? null);
        $rightsBasis = $this->assertRightsBasis($source['rights_basis'] ?? null, $sourceType);
        $url = $this->normalizeSourceUrl($source['source_url'] ?? null);

        if (in_array($sourceType, ['authorized_repost', 'link_summary'], true) && $url === null) {
            throw ContentSourceValidationException::required(sprintf(
                'source_type=%s 时每条来源都必须填写 source_url。',
                $sourceType,
            ));
        }

        $rightsScope = $this->normalizeOptionalText($source['rights_scope'] ?? null, 'rights_scope', 255);
        $evidenceRef = $this->normalizeOptionalText($source['evidence_ref'] ?? null, 'evidence_ref', 255);

        if ($sourceType === 'authorized_repost') {
            if ($rightsScope === null) {
                throw ContentSourceValidationException::required('authorized_repost 必须填写 rights_scope（正文 / 图片 / 改编范围）。');
            }

            if ($evidenceRef === null) {
                throw ContentSourceValidationException::required('authorized_repost 必须填写 evidence_ref（书面授权依据引用）。');
            }
        }

        return [
            'source_name'        => $name,
            'source_url'         => $url,
            'source_verified_at' => $verifiedAt,
            'rights_basis'       => $rightsBasis,
            'rights_scope'       => $rightsScope,
            'evidence_ref'       => $evidenceRef,
        ];
    }

    private function normalizeSourceVerifiedAt(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            throw ContentSourceValidationException::required('每条来源都必须有 source_verified_at（UTC 核验时间）。');
        }

        if ($value instanceof DateTimeInterface) {
            $verifiedAt = Carbon::createFromInterface($value)->utc();
        } elseif (is_string($value)) {
            try {
                $verifiedAt = Carbon::parse($value)->utc();
            } catch (\Throwable) {
                throw ContentSourceValidationException::required(sprintf(
                    'source_verified_at 的时间「%s」无法解析。',
                    $value,
                ));
            }
        } else {
            throw ContentSourceValidationException::required('source_verified_at 只接受时间字符串或日期对象。');
        }

        if ($verifiedAt->gt(Carbon::now('UTC'))) {
            throw ContentSourceValidationException::required('source_verified_at 不得晚于当前时间。');
        }

        return $verifiedAt;
    }

    private function assertRightsBasis(mixed $value, string $sourceType): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw ContentSourceValidationException::required('每条来源都必须有 rights_basis。');
        }

        $allowed = array_values((array) config('ecosystem_content.rights_bases', []));

        if (! in_array($value, $allowed, true)) {
            throw ContentSourceValidationException::required(sprintf('未知的 rights_basis「%s」。', $value));
        }

        $matching = array_values((array) config('ecosystem_content.source_type_rights_basis.'.$sourceType, []));

        if (! in_array($value, $matching, true)) {
            throw ContentSourceValidationException::required(sprintf(
                'source_type=%s 不能使用 rights_basis=%s；允许：%s。',
                $sourceType,
                $value,
                $matching === [] ? '(无)' : implode(' / ', $matching),
            ));
        }

        return $value;
    }

    private function normalizeSourceUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ContentSourceValidationException::required('source_url 必须是 URL 字符串或 NULL。');
        }

        try {
            $this->sourceValidator->assertSourceUrl($value);
        } catch (EventSourceValidationException $exception) {
            // 统一收敛为内容域错误码，避免前端把内容来源错误当成活动来源错误处理。
            throw ContentSourceValidationException::required($exception->getMessage());
        }

        return $value;
    }

    /**
     * 整组替换来源：先按 draft 守卫删除旧记录，再按输入顺序新增。
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, ContentSource>
     */
    private function replaceSources(ContentRevision $revision, array $sources): array
    {
        foreach ($revision->sources()->orderBy('sort_order')->get() as $existing) {
            // ContentSource::deleting 守卫父 revision 必须仍是 draft。
            $existing->delete();
        }

        $created = [];

        foreach ($sources as $source) {
            $created[] = ContentSource::create([
                'revision_id'        => (int) $revision->getKey(),
                'source_name'        => $source['source_name'],
                'source_url'         => $source['source_url'],
                'source_verified_at' => $source['source_verified_at'],
                'rights_basis'       => $source['rights_basis'],
                'rights_scope'       => $source['rights_scope'],
                'evidence_ref'       => $source['evidence_ref'],
                'sort_order'         => (int) $source['sort_order'],
            ]);
        }

        return $created;
    }
}
