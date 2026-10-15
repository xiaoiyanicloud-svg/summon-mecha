<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\Scene;

/**
 * 小召·召唤引擎 v1(确定性规则匹配,不依赖外部 LLM)。
 *
 * 战略定位:AI 召唤是平台的心智入口。「说需求 → 召唤出可交付的方案+机器」
 * 是区别于设备租赁平台的核心能力,v1 用关键词打分把这条链路跑通,后续可平滑
 * 升级为 LLM 理解(接口结构不变:意图 → 场景 → 机型 → 方案)。
 *
 * 匹配逻辑:
 *   1. 需求文本拆词(空格/、/,/市 等分隔 + 内置同义词表:展会→展台/品牌…)
 *   2. 场景打分:name/description 命中词数 + 权重
 *   3. 机型打分:rent 分类商品 name 命中词数
 *   4. 返回:最佳场景(含方案) + 推荐机型 + 备选场景
 */
class SummonController extends APIController
{
    public function __construct(protected ProductRepository $productRepository) {}

    /**
     * 内置同义词表:把口语需求映射到场景/商品词汇(v1 可持续沉淀)。
     */
    private function synonyms(): array
    {
        return [
            '展会'    => ['展会', '展台', '展览', '展厅', '迎宾', '讲解', '引流'],
            '活动'    => ['活动', '演出', '表演', '婚', '年会', '节庆'],
            '酒店'    => ['酒店', '客房', '配送', '迎宾'],
            '餐饮'    => ['餐饮', '餐厅', '传菜', '送餐', '火锅'],
            '商场'    => ['商场', '门店', '引流', '导购'],
            '巡检'    => ['巡检', '巡逻', '安防', '园区', '矿区', '机房'],
            '仓储'    => ['仓储', '仓库', '分拣', '搬运', '码垛'],
            '仓库'    => ['仓储', '仓库', '分拣', '搬运', '码垛'],
            '工厂'    => ['工厂', '产线', '装配', '焊接', '工业'],
            '医院'    => ['医院', '导诊', '药房', '康养', '养老'],
            '教育'    => ['教育', '科教', '研学', '学校', '编程'],
            '农业'    => ['农业', '大棚', '果园', '农田'],
            '家用'    => ['家庭', '陪伴', '扫地', '家用', '儿童'],
            '人形'    => ['人形', '仿生', 'Walker'],
            '机器狗'  => ['四足', '机器狗', 'Go2', 'CyberDog'],
            '配送机器人' => ['配送', '送餐', '传菜'],
        ];
    }

    public function summon(Request $request): JsonResponse
    {
        // AI 版开关(见 config/summon.php,由 .env 的 SUMMON_AI_ENABLED 控制)。
        // 关闭时(默认)下面的 rule-v1 逻辑一行不动,行为与从前完全一致。
        if (config('summon.ai_enabled')) {
            return app(SummonAiController::class)->summon($request);
        }

        $q = trim((string) $request->input('q', ''));

        if ($q === '') {
            return $this->fail(ApiErrorCode::SUMMON_QUERY_REQUIRED, '请描述你的需求', 422);
        }

        // 1. 词集:原句 + 拆词 + 同义词展开
        $words = collect(preg_split('/[\s,，、。;；!！?？]+/u', $q) ?: [])->filter()->push($q)->unique()->values();
        foreach ($this->synonyms() as $trigger => $expand) {
            if (mb_stripos($q, $trigger) !== false) {
                $words = $words->merge($expand);
            }
        }
        $words = $words->unique()->values()->all();

        // 2. 场景打分
        $scenes = Scene::where('status', 1)->get()
            ->map(function ($scene) use ($words, $q) {
                $haystack = $scene->name.' '.$scene->description;
                $score = 0;
                foreach ($words as $w) {
                    if ($w !== '' && mb_stripos($haystack, $w) !== false) {
                        $score += 2;
                    }
                }
                if ($q !== '' && mb_stripos($haystack, $q) !== false) {
                    $score += 3;
                }

                return ['scene' => $scene, 'score' => $score];
            })
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->values();

        // 3. 机型打分(rent 分类商品)
        $rentCategoryId = \Webkul\Category\Models\Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'rent'))
            ->value('id');

        $machines = collect();

        if ($rentCategoryId) {
            $machines = app(ProductRepository::class)
                ->setSearchEngine('database')
                ->getAll([
                    'category_id'          => $rentCategoryId,
                    'limit'                => 200,
                    'page'                 => 1,
                    'channel_id'           => core()->getCurrentChannel()->id,
                    'status'               => 1,
                    'visible_individually' => 1,
                ])
                ->map(function ($product) use ($words) {
                    $name = $product->name ?? '';
                    $score = 0;
                    foreach ($words as $w) {
                        if ($w !== '' && mb_stripos($name, $w) !== false) {
                            $score += 2;
                        }
                    }

                    return ['product' => $product, 'score' => $score];
                })
                ->filter(fn ($r) => $r['score'] > 0)
                ->sortByDesc('score')
                ->take(6)
                ->values()
                ->map(fn ($r) => (new \Webkul\Shop\Http\Resources\ProductResource($r['product']))->jsonSerialize());
        }

        // 4. 组装:最佳场景(含方案) + 备选场景
        $topScene = $scenes->first();

        return response()->json([
            'success' => true,
            'q'       => $q,
            'scene'   => $topScene ? $this->formatScene($topScene['scene']) : null,
            'machines' => $machines->values(),
            'alternatives' => $scenes->skip(1)->take(3)->values()->map(fn ($r) => [
                'id'    => $r['scene']->id,
                'name'  => $r['scene']->name,
                'desc'  => $r['scene']->description,
            ]),
            'engine' => 'rule-v1',
        ]);
    }

    /**
     * 场景格式化:与 /api/scenes/{id} 同构(scheme 由模型 accessor 组装)。
     */
    private function formatScene($scene): array
    {
        return [
            'id'            => $scene->id,
            'name'          => $scene->name,
            'category'      => $scene->category,
            'description'   => $scene->description,
            'banner'        => $scene->banner_url,
            'icon'          => $scene->icon,
            'bg_color'      => $scene->bg_color,
            'is_hot'        => (bool) $scene->is_hot,
            'consult_count' => $scene->consult_count,
            'scheme'        => $scene->scheme,
        ];
    }
}
