<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Services\SkillDelivery\SkillMarketService;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillCategory;
use Webkul\Shop\Models\SkillCompatibilityEvidence;
use Webkul\Shop\Models\SkillTag;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\SkillPackage\SkillManifest;

/**
 * 技能类目/标签/筛选 —— 公开读取(v3.4 步骤 6/7)。
 *
 * 三件事:
 *   - `categories` / `tags`:类目与标签字典(标签按 type 分栏:motion_type/scene/difficulty);
 *   - `filters`:技能市场筛选面板的候选项(机型/通道/风险等级);
 *   - `search`:技能搜索 —— **支持四个筛选维度**(机型/通道/动作类型/风险等级,B-9)。
 *
 * ⚠ 四个筛选维度里,机型/通道/风险等级落在 `skill_versions`(已发布版本)上,
 *   动作类型落在 `skill_tag`(type=motion_type)+ `skill_tag_pivot` 上。
 *   三者的语义与 `CompatibilityService` / `RiskDisclosureService` **同一口径**,
 *   不在这里另立一套判据(否则"能筛到"和"能装上"会互相打架)。
 */
class SkillCatalogController extends APIController
{
    /**
     * 类目列表。
     */
    public function categories(): JsonResponse
    {
        $rows = SkillCategory::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (SkillCategory $c) => [
                'id'   => $c->id,
                'code' => $c->code,
                'name' => $c->name,
            ])->values(),
        ]);
    }

    /**
     * 标签列表(可按 type 过滤:motion_type/scene/difficulty)。
     */
    public function tags(Request $request): JsonResponse
    {
        $q = SkillTag::query()->orderBy('sort_order');

        if ($request->filled('type')) {
            $q->where('type', $request->input('type'));
        }

        return response()->json([
            'data' => $q->get()->map(fn (SkillTag $t) => [
                'id'   => $t->id,
                'name' => $t->name,
                'type' => $t->type,
            ])->values(),
        ]);
    }

    /**
     * 筛选面板候选项(B-9)。
     *
     * 返回技能市场「筛选」行要用的三组选项:机型 / 交付通道 / 风险等级。
     * 动作类型("动作形态"标签)走既有的 `tags?type=motion_type` —— 不在这里重复下发。
     *
     * ⚠ 只给**真实存在**的选项,不编占位项:
     *   - 机型库为空 → models 返回空数组,前端不展示机型筛选(不假装有机型可选);
     *   - 通道只列 P0 两个真实通道(agent/manual);厂商云(vendor:{code})是 P1,未就绪则不发。
     */
    public function filters(): JsonResponse
    {
        $models = DeviceModel::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->where('status', DeviceModel::STATUS_ACTIVE)
            ->get(['model_code', 'model_name', 'brand']);

        return response()->json([
            'data' => [
                'models' => $models->map(fn (DeviceModel $m) => [
                    'code'  => $m->model_code,
                    'name'  => $m->model_name,
                    'brand' => $m->brand,
                ])->values(),
                'channels' => [
                    ['value' => SkillAsset::DELIVERY_AGENT,  'label' => '自动下发'],
                    ['value' => SkillAsset::DELIVERY_MANUAL, 'label' => '人工安装'],
                ],
                'risks' => [
                    ['value' => 'low',    'label' => '低风险'],
                    ['value' => 'medium', 'label' => '中风险'],
                    ['value' => 'high',   'label' => '高风险'],
                ],
            ],
        ]);
    }

    /**
     * 技能发布历史(B-16)。
     *
     * 只回**已发布 / 已归档**的版本(草稿从未发布,不对外)。新版在前。
     * 每条带:版本号 / 发布通道(stable|beta)/ 交付通道 / 风险等级 / 发布时间 / 变更日志。
     *
     * ⚠ 与 `RiskDisclosureService` 一样用 `status=published` 的最新一条作为"当前版本",
     *   前端据此展示"当前在用哪一版"。这是**只读**信息,买之前就该看到。
     */
    public function versions(int $productId): JsonResponse
    {
        // S-8 · 熔断即下架:被熔断的技能详情**明确报错**,不静默 404 —— 静默消失会让用户
        //   以为是自己的网络/平台坏了,而这是平台的**有意动作**,应当说清(码 + 人话)。
        //   与市场搜索同一口径('sale' 门 = 任何生效熔断都拦),故"列表里没有"与"点进来报错"一致。
        if (app(KillSwitchService::class)->isBlockedFor($productId, 'sale')) {
            return $this->fail(ApiErrorCode::SKILL_BLOCKED, '该技能已被平台下架,暂不可查看。', 409);
        }

        if ($problem = app(SkillMarketService::class)->availability($productId, true)) {
            return $this->fail($problem['code'], $problem['message'], $problem['status']);
        }

        $rows = \DB::table('skill_versions')
            ->where('product_id', $productId)
            ->whereIn('status', [SkillVersion::STATUS_PUBLISHED, SkillVersion::STATUS_ARCHIVED])
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'version', 'channel', 'status', 'risk_level', 'compatibility', 'delivery_channels', 'permissions', 'released_at', 'changelog']);

        // A rollback may leave an older published version behind many newer archived releases.
        // The bounded history must still contain the actual version buyers can acquire.
        $currentId = app(SkillMarketService::class)->currentVersions()->select('id')->where('product_id', $productId)->value('id');
        if ($currentId && ! $rows->contains('id', $currentId)) {
            $current = \DB::table('skill_versions')->where('id', $currentId)
                ->first(['id', 'version', 'channel', 'status', 'risk_level', 'compatibility', 'delivery_channels', 'permissions', 'released_at', 'changelog']);
            if ($current) $rows->push($current);
        }

        // A3 · 声明与证据并列展示：`compatibility` 是**声明**（开发者说支持什么），
        //   `compatibility_evidence_*` 是**证据**（真机/仿真/静态/人工验证到哪一步）。
        //   两者都不是"已适配"的结论；逐台设备的判定由 CompatibilityService 给出。
        $versionIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();
        $evidenceCounts = [];
        $evidenceLatest = [];

        if ($versionIds !== []) {
            $evidence = SkillCompatibilityEvidence::query()
                ->whereIn('skill_version_id', $versionIds)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->get();

            $evidenceCounts = $evidence->groupBy('skill_version_id')->map->count()->all();
            $evidenceLatest = $evidence->unique('skill_version_id')->keyBy('skill_version_id')->all();
        }

        return response()->json([
            'current_version_id' => $currentId ? (int) $currentId : null,
            'data' => $rows->map(fn ($v) => [
                'id'                => (int) $v->id,
                'version'           => $v->version,
                // 发布通道(stable/beta)—— 与交付通道(delivery_channels)不是一回事,见术语纪律
                'channel'           => $v->channel,
                'channel_label'     => SkillVersion::CHANNEL_LABELS[$v->channel] ?? SkillVersion::CHANNEL_LABELS[SkillVersion::CHANNEL_STABLE],
                'status'            => $v->status,
                'risk_level'        => $v->risk_level,
                // 声明：原样回开发者提交的 compatibility（机型/固件/SDK/Agent 条件）。
                // 未声明 → null，不是空对象；前端据此区分"没写"与"写了空"。
                'compatibility'     => json_decode((string) $v->compatibility, true) ?: null,
                'compatibility_evidence_count'  => (int) ($evidenceCounts[$v->id] ?? 0),
                'compatibility_evidence_latest' => isset($evidenceLatest[$v->id])
                    ? $this->evidenceSummary($evidenceLatest[$v->id])
                    : null,
                'delivery_channels' => json_decode((string) $v->delivery_channels, true) ?: [],
                // D-8 改判版 · 权限声明可见化：买之前就能看清"这技能会用到什么"。
                //   `permissions` = 开发者声明的原始枚举(与 manifest 一致)；
                //   `permission_labels` = 同一批权限的人话标签,取自 SkillManifest::PERMISSION_LABELS
                //   （**唯一**中文来源,前端与风险告知都从这里取语义,不另抄一份）。
                //   未声明权限 → 两个键都是空数组,不编造一行"未声明权限"占位。
                'permissions'       => $permissions = json_decode((string) $v->permissions, true) ?: [],
                'permission_labels' => array_map(
                    fn ($p) => SkillManifest::PERMISSION_LABELS[$p] ?? $p,
                    $permissions
                ),
                'released_at'       => $v->released_at,
                'changelog'         => $v->changelog,
            ])->values(),
        ]);
    }

    /**
     * 版本详情里的"最新一条证据"摘要 —— 只做提示，不合成适配结论。
     *
     * @return array<string, mixed>
     */
    protected function evidenceSummary(SkillCompatibilityEvidence $e): array
    {
        return [
            'id'                  => (int) $e->id,
            'model_code'          => $e->model_code,
            'firmware_version'    => $e->firmware_version,
            'development_version' => $e->development_version,
            'sdk_version'         => $e->sdk_version,
            'source'              => $e->source,
            'source_label'        => $e->sourceLabel(),
            'evidence_type'       => $e->evidence_type,
            'evidence_type_label' => $e->evidenceTypeLabel(),
            'result'              => $e->result,
            'result_label'        => $e->resultLabel(),
            'asset_id'            => $e->asset_id !== null ? (int) $e->asset_id : null,
            'asset_hash'          => $e->asset_hash,
            'observed_at'         => $e->observed_at?->toIso8601String(),
        ];
    }

    /**
     * 技能搜索(v3.4 步骤 7;筛选维度 B-9)。
     *
     * 只搜 `skill` 分类下的商品。参数:
     *   - `q`       名称模糊匹配(name LIKE)
     *   - `model`   机型 code(device_model.model_code)—— 按交付通道初筛，不能当作真机适配证明
     *   - `channel` 交付通道(agent / manual)—— 技能声明的通道含它
     *   - `motion`  动作形态(skill_tag.type=motion_type 的 name)
     *   - `risk`    风险等级(low/medium/high)
     *
     * 返回精简列表(id/name/price/special_price)。用 product_flat 直查
     * (避免 locale/channel 重复),按 product_id 去重。
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q'       => ['nullable', 'string', 'max:120'],
            'model'   => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', 'string', 'max:64'],
            'motion'  => ['nullable', 'string', 'max:64'],
            'risk'    => ['nullable', 'string', 'in:low,medium,high'],
            'page'    => ['nullable', 'integer', 'min:1', 'max:10000'],
            'limit'   => ['nullable', 'integer', 'min:1', 'max:40'],
        ]);

        $q       = trim((string) $request->input('q', ''));
        $model   = trim((string) $request->input('model', ''));
        $channel = trim((string) $request->input('channel', ''));
        $motion  = trim((string) $request->input('motion', ''));
        $risk    = trim((string) $request->input('risk', ''));

        $query = app(SkillMarketService::class)->visibleRows();

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where('name', 'like', '%'.$escaped.'%');
        }

        // 无筛选时也只展示存在可交付发布版本的技能；筛选只读当前已发布版本。
        // 与 versions()/CompatibilityService 相同：released_at DESC，id DESC。
        $query->whereIn('product_id', $this->versionConstrainedProductIds($risk, $channel, $model));

        // 动作形态 —— 标签(type=motion_type)命中其一即可
        if ($motion !== '') {
            $query->whereIn('product_id', $this->motionTagProductIds($motion));
        }

        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 40);
        // DISTINCT before limiting: one product may have several channel/locale rows.
        $rows = $query->select(['product_id', 'name', 'price', 'special_price', 'url_key', 'short_description'])
            ->distinct()->orderByDesc('product_id')->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $images = \Webkul\Product\Models\ProductImage::query()->whereIn('product_id', $rows->pluck('product_id'))
            ->orderBy('position')->orderBy('id')->get()->groupBy('product_id');

        // 去重(同商品多语言行)
        $seen = [];
        $data = [];
        foreach ($rows as $r) {
            if (isset($seen[$r->product_id])) {
                continue;
            }
            $seen[$r->product_id] = true;
            $data[] = [
                'id'            => (int) $r->product_id,
                'name'          => $r->name,
                'price'         => (float) $r->price,
                'special_price' => $r->special_price !== null ? (float) $r->special_price : null,
                'image'         => $images->get($r->product_id)?->first()?->url,
                'summary'       => mb_substr(strip_tags((string) $r->short_description), 0, 160),
                'market_eligible' => true,
            ];
        }

        return response()->json([
            'data'    => $data,
            'query'   => $q,
            'pagination' => ['page' => $page, 'per_page' => $limit, 'has_more' => $hasMore],
            'compatibility_basis' => 'delivery_channel_only',
            'filters' => array_filter([
                'model'   => $model !== '' ? $model : null,
                'channel' => $channel !== '' ? $channel : null,
                'motion'  => $motion !== '' ? $motion : null,
                'risk'    => $risk !== '' ? $risk : null,
            ]),
        ]);
    }

    /**
     * 命中的商品 id 子查询：当前已发布版本同时满足各筛选。
     * 新草稿不遮蔽旧发布；旧发布不能让已经不满足条件的新版继续命中。
     */
    protected function versionConstrainedProductIds(string $risk, string $channel, string $model)
    {
        $sub = app(SkillMarketService::class)->currentVersions();

        if ($risk !== '') {
            $sub->where('risk_level', $risk);
        }

        if ($channel !== '') {
            $this->whereDeclaresChannel($sub, $channel);
        }

        if ($model !== '') {
            // 机型可用通道(未知机型降级为仅 manual —— 与 CompatibilityService 一致)
            $declared = DeviceModel::query()->where('model_code', $model)->value('comm_channels');
            $modelChannels = is_array($declared) && $declared ? $declared : [SkillAsset::DELIVERY_MANUAL];

            $sub->where(function ($inner) use ($modelChannels) {
                foreach (array_values($modelChannels) as $i => $ch) {
                    $this->whereDeclaresChannel($inner, (string) $ch, $i > 0);
                }
            });
        }

        return $sub;
    }

    /**
     * 命中的商品 id 子查询:带指定动作形态标签。
     */
    protected function motionTagProductIds(string $motionName)
    {
        return \DB::table('skill_tag_pivot')
            ->select('skill_id')
            ->whereIn('tag_id', function ($sub) use ($motionName) {
                $sub->select('id')->from('skill_tag')
                    ->where('type', SkillTag::TYPE_MOTION)
                    ->where('name', $motionName);
            });
    }

    /**
     * 给查询加一条「该版本声明含通道 $channel」的条件。
     *
     * ⚠ `delivery_channels` 为 NULL 或 [] 的旧版本视为 `['manual']` —— 与
     *   `CompatibilityService` 的缺省口径一致,否则"能筛到"和"能装上"会互相打架。
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function whereDeclaresChannel($query, string $channel, bool $or = false): void
    {
        $query->where(function ($w) use ($channel) {
            if ($channel === SkillAsset::DELIVERY_MANUAL) {
                $w->whereNull('delivery_channels')
                    ->orWhereJsonLength('delivery_channels', 0)
                    ->orWhereJsonContains('delivery_channels', SkillAsset::DELIVERY_MANUAL);
            } else {
                $w->whereJsonContains('delivery_channels', $channel);
            }
        }, null, null, $or ? 'or' : 'and');
    }
}
