<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\SkillCategory;
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
            ->get(['model_code', 'model_name', 'brand']);

        return response()->json([
            'data' => [
                'models' => $models->map(fn (DeviceModel $m) => [
                    'code'  => $m->model_code,
                    'name'  => $m->model_name,
                    'brand' => $m->brand,
                ])->values(),
                'channels' => [
                    ['value' => 'agent',  'label' => '自动下发'],
                    ['value' => 'manual', 'label' => '人工安装'],
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

        $rows = \DB::table('skill_versions')
            ->where('product_id', $productId)
            ->whereIn('status', [SkillVersion::STATUS_PUBLISHED, SkillVersion::STATUS_ARCHIVED])
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'version', 'channel', 'status', 'risk_level', 'delivery_channels', 'permissions', 'released_at', 'changelog']);

        return response()->json([
            'data' => $rows->map(fn ($v) => [
                'id'                => (int) $v->id,
                'version'           => $v->version,
                // 发布通道(stable/beta)—— 与交付通道(delivery_channels)不是一回事,见术语纪律
                'channel'           => $v->channel,
                'channel_label'     => $v->channel === 'beta' ? '测试版' : '正式版',
                'status'            => $v->status,
                'risk_level'        => $v->risk_level,
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
     * 技能搜索(v3.4 步骤 7;筛选维度 B-9)。
     *
     * 只搜 `skill` 分类下的商品。参数:
     *   - `q`       名称模糊匹配(name LIKE)
     *   - `model`   机型 code(device_model.model_code)—— 技能已发布版本声明的交付通道
     *               与该机型可用通道**有交集**即命中(与 CompatibilityService 同一判据)
     *   - `channel` 交付通道(agent / manual)—— 技能声明的通道含它
     *   - `motion`  动作形态(skill_tag.type=motion_type 的 name)
     *   - `risk`    风险等级(low/medium/high)
     *
     * 返回精简列表(id/name/price/special_price)。用 product_flat 直查
     * (避免 locale/channel 重复),按 product_id 去重。
     */
    public function search(Request $request): JsonResponse
    {
        $q       = trim((string) $request->input('q', ''));
        $model   = trim((string) $request->input('model', ''));
        $channel = trim((string) $request->input('channel', ''));
        $motion  = trim((string) $request->input('motion', ''));
        $risk    = trim((string) $request->input('risk', ''));

        // skill 分类 id(取分类翻译里 slug=skill 的 category_id)
        $categoryId = \DB::table('category_translations')->where('slug', 'skill')->value('category_id');

        $query = \DB::table('product_flat')
            ->where('status', 1)
            ->where('visible_individually', 1)
            // ⚠ 只取当前语言行:多语言行里 name 只在对应语言有值,不过滤会取到 null 行
            ->where('locale', app()->getLocale())
            ->whereNotNull('name');

        // S-8 · 熔断即下架:被熔断的技能**不得出现在市场列表**。
        //   规则(sale 门 = 任何生效熔断都算停售)收敛在 KillSwitchService,这里只把它的
        //   结果 whereNotIn 到查询层 —— 可见性判断归查询,不在这里逐行过滤。
        $blockedIds = app(KillSwitchService::class)->saleBlockedSkillIds();
        if (! empty($blockedIds)) {
            $query->whereNotIn('product_id', $blockedIds);
        }

        if ($categoryId) {
            $query->whereIn('product_id', function ($sub) use ($categoryId) {
                $sub->select('product_id')->from('product_categories')->where('category_id', $categoryId);
            });
        }

        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where('name', 'like', '%'.$escaped.'%');
        }

        // 机型 / 通道 / 风险等级 —— 都约束在**已发布版本**上
        if ($risk !== '' || $channel !== '' || $model !== '') {
            $query->whereIn('product_id', $this->versionConstrainedProductIds($risk, $channel, $model));
        }

        // 动作形态 —— 标签(type=motion_type)命中其一即可
        if ($motion !== '') {
            $query->whereIn('product_id', $this->motionTagProductIds($motion));
        }

        $rows = $query->orderByDesc('product_id')->limit(40)->get(['product_id', 'name', 'price', 'special_price', 'url_key']);

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
            ];
        }

        return response()->json([
            'data'    => $data,
            'query'   => $q,
            'filters' => array_filter([
                'model'   => $model !== '' ? $model : null,
                'channel' => $channel !== '' ? $channel : null,
                'motion'  => $motion !== '' ? $motion : null,
                'risk'    => $risk !== '' ? $risk : null,
            ]),
        ]);
    }

    /**
     * 命中的商品 id 子查询:已发布版本同时满足 风险等级 / 通道 / 机型。
     */
    protected function versionConstrainedProductIds(string $risk, string $channel, string $model)
    {
        $sub = \DB::table('skill_versions')->select('product_id')->where('status', 'published');

        if ($risk !== '') {
            $sub->where('risk_level', $risk);
        }

        if ($channel !== '') {
            $this->whereDeclaresChannel($sub, $channel);
        }

        if ($model !== '') {
            // 机型可用通道(未知机型降级为仅 manual —— 与 CompatibilityService 一致)
            $declared = DeviceModel::query()->where('model_code', $model)->value('comm_channels');
            $modelChannels = is_array($declared) && $declared ? $declared : ['manual'];

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
     * ⚠ `delivery_channels` 为 NULL 的旧版本视为 `['manual']` —— 与
     *   `CompatibilityService` 的缺省口径一致,否则"能筛到"和"能装上"会互相打架。
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function whereDeclaresChannel($query, string $channel, bool $or = false): void
    {
        $query->where(function ($w) use ($channel) {
            if ($channel === 'manual') {
                $w->whereNull('delivery_channels')
                    ->orWhereJsonContains('delivery_channels', 'manual');
            } else {
                $w->whereJsonContains('delivery_channels', $channel);
            }
        }, null, null, $or ? 'or' : 'and');
    }
}
