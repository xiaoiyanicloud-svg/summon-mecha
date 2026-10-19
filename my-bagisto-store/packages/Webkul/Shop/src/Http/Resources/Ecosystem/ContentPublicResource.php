<?php

namespace Webkul\Shop\Http\Resources\Ecosystem;

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;

/**
 * 公开资讯 DTO（ADR-0001 G1-D08 / 规划 §6.3 / G1-10）。
 *
 * **显式构造，禁止序列化模型**。字段只来自：
 *   · 父对象：`slug` / `type` / `published_at` / `first_published_at`；
 *   · 当前 published revision：标题、摘要、作者、更正说明、正文、`revision_no`；
 *   · revision 的来源快照：名称、URL、核验时间、权利依据与范围。
 *
 * 永不返回：`id`（内部 ID）、`working_revision_id` / `approved_revision_id`、
 * `review_note` / `reviewed_by`、`evidence_ref`、`storage_key`、联系人。
 */
final class ContentPublicResource
{
    /**
     * @param  array<int, array{name: string, url: string|null, verified_at: string|null, rights_basis: string, rights_scope: string|null}>  $sources
     * @param  array<int, array{id: int, role: string, alt_text: string|null, url: string}>  $media
     */
    public function __construct(
        private readonly ContentPost $post,
        private readonly ContentRevision $revision,
        private readonly array $sources,
        private readonly ?string $bodyHtml,
        private readonly array $media,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $timezone = $this->platformTimezone();

        return [
            'slug'               => (string) $this->post->slug,
            'type'               => (string) $this->post->type,
            'title'              => (string) $this->revision->title,
            'summary'            => $this->nullableString($this->revision->summary),
            'author'             => [
                'name' => (string) $this->revision->author_name,
                'org'  => $this->nullableString($this->revision->author_org),
            ],
            'sources'            => $this->sources,
            'published_at'       => $this->iso($this->post->published_at, $timezone),
            'first_published_at' => $this->iso($this->post->first_published_at, $timezone),
            'revision_no'        => (int) $this->revision->revision_no,
            'correction_note'    => $this->nullableString($this->revision->correction_note),
            'body_html'          => $this->bodyHtml,
            'media'              => $this->media,
        ];
    }

    /**
     * 来源 DTO：刻意不输出 `evidence_ref`（私有依据引用）。
     *
     * @param  iterable<int, \Webkul\Shop\Models\ContentSource>  $sources
     * @return array<int, array{name: string, url: string|null, verified_at: string|null, rights_basis: string, rights_scope: string|null}>
     */
    public static function sources(iterable $sources, string $timezone): array
    {
        $result = [];

        foreach ($sources as $source) {
            $result[] = [
                'name'        => (string) $source->source_name,
                'url'         => $source->source_url !== null ? (string) $source->source_url : null,
                'verified_at' => $source->source_verified_at instanceof Carbon
                    ? $source->source_verified_at->copy()->timezone($timezone)->toIso8601String()
                    : null,
                'rights_basis' => (string) $source->rights_basis,
                'rights_scope' => $source->rights_scope !== null ? (string) $source->rights_scope : null,
            ];
        }

        return $result;
    }

    private function platformTimezone(): string
    {
        return (string) config('app.timezone', 'Asia/Shanghai');
    }

    private function iso(mixed $value, string $timezone): ?string
    {
        if (! $value instanceof Carbon) {
            return null;
        }

        return $value->copy()->timezone($timezone)->toIso8601String();
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
