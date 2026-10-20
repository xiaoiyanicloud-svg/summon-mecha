<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Scene;

class SceneCatalogService
{
    /** 目录资格不代表现场能力、档期或真机适配已经验证。 */
    public function rentalProductIds(int $channelId, ?array $only = null): array
    {
        $query = DB::table('products as p')
            ->join('product_flat as pf', 'pf.product_id', '=', 'p.id')
            ->join('product_channels as pch', 'pch.product_id', '=', 'p.id')
            ->join('channels as ch', 'ch.id', '=', 'pch.channel_id')
            ->join('product_categories as pc', 'pc.product_id', '=', 'p.id')
            ->join('category_translations as ct', 'ct.category_id', '=', 'pc.category_id')
            ->where('ct.slug', 'rent')
            ->where('pch.channel_id', $channelId)
            ->whereColumn('pf.channel', 'ch.code')
            ->where('pf.status', 1)
            ->where('pf.visible_individually', 1)
            ->where('p.type', 'simple');

        if ($only !== null) {
            $query->whereIn('p.id', $only);
        }

        return $query->distinct()->pluck('p.id')->map(fn ($id) => (int) $id)->all();
    }

    public function summary(Scene $scene): array
    {
        if (config('scenes.publication_v2', false)) {
            $r = app(ScenePublicationService::class)->active((int) $scene->id);
            $c = SceneWorkflow::unpack($r->content);

            return ['id'            => $scene->id, 'name' => $c['name'], 'category' => $c['category'], 'description' => $c['description'],
                'banner'            => $c['banner'] ?? null, 'tags' => $c['tags'], 'sort' => $scene->sort, 'is_hot' => (bool) $scene->is_hot,
                'icon'              => $scene->icon, 'bg_color' => $scene->bg_color, 'consult_count' => null, 'consult_count_source' => 'not_published',
                'scene_revision_id' => (int) $r->id, 'public_seq' => (int) $scene->public_seq];
        }

        return [
            'id'          => $scene->id,
            'name'        => $scene->name,
            'category'    => $scene->category,
            'description' => $scene->description,
            'banner'      => $scene->banner_url,
            'sort'        => $scene->sort,
            'icon'        => $scene->icon,
            'bg_color'    => $scene->bg_color,
            'tags'        => $scene->tags ?? [],
            'is_hot'      => (bool) $scene->is_hot,
            // 旧字段可人工编辑，不作为实际咨询人数公开。
            'consult_count'        => null,
            'consult_count_source' => 'legacy_unverified',
        ];
    }

    public function detail(Scene $scene, int $channelId): array
    {
        if (config('scenes.publication_v2', false)) {
            $r = app(ScenePublicationService::class)->active((int) $scene->id);
            $c = SceneWorkflow::unpack($r->content);
            $robots = $this->rentalProductIds($channelId, $c['robots']);

            return $this->summary($scene) + ['matched' => true, 'requirement_schema' => SceneWorkflow::unpack($r->requirement_schema),
                'acceptance_template'                  => SceneWorkflow::unpack($r->acceptance_template), 'deliverables' => $c['deliverables'],
                'scheme'                               => ['desc' => $c['description'], 'icon' => $scene->icon, 'bgColor' => $scene->bg_color,
                    'consultCount'                                => null, 'stats' => null, 'tags' => $c['tags'], 'pains' => [], 'scenes' => [],
                    'benefits'                                    => array_map(fn ($d) => ['title' => $d, 'desc' => '', 'icon' => '✓'], $c['deliverables']), 'robots' => $robots],
                'catalog_status' => $robots ? 'configured' : 'no_configured_products', 'delivery_status' => 'requires_confirmation'];
        }
        $scheme = $scene->scheme;
        $scheme['robots'] = $this->rentalProductIds($channelId, (array) $scene->robots);
        $scheme['consultCount'] = null;
        $scheme['stats'] = null;
        // 现阶段没有量化收益证据结构；保留内容说明，不公开未经验证的数字。
        $scheme['scenes'] = collect($scene->scheme_scenes ?? [])->filter(fn ($item) => is_array($item))->map(fn ($item) => [
            'name' => $item['name'] ?? '', 'desc' => $item['desc'] ?? '',
        ])->values()->all();

        return $this->summary($scene) + [
            'matched'         => true,
            'scheme'          => $scheme,
            'catalog_status'  => $scheme['robots'] ? 'configured' : 'no_configured_products',
            'delivery_status' => 'requires_confirmation',
        ];
    }
}
