<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\SkillAudit\ReviewGate;

/**
 * 开发者门户 · 我的技能 / 版本 / 传包 / 提审 —— v3.4 步骤 9。
 *
 * ⚠ 本控制器只做"校验 + 调 service + 渲染"。**归属校验一次都不在这里写** ——
 *   全部在 DeveloperSkillService 的 findOwned* 里，且一律是查询层过滤。
 *   控制器拿到的 null 一律映射成 404（不用 403，避免枚举他人资源 id）。
 */
class SkillController extends Controller
{
    public function __construct(
        protected DeveloperSkillService $skills,
        protected ReviewGate $gate,
        protected OperationLogger $ops,
    ) {}

    public function index(Request $request)
    {
        return view('shop::dev.skills.index', [
            'profile' => $this->profile($request),
            'skills'  => $this->skills->listSkills($this->profile($request)),
        ]);
    }

    public function create()
    {
        return view('shop::dev.skills.create');
    }

    public function store(Request $request)
    {
        $payload = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'price'             => ['required', 'numeric', 'min:0'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string', 'max:5000'],
        ]);

        $product = $this->skills->createSkill($this->profile($request), $payload);

        if (! $product) {
            return back()->withErrors(['name' => '开发者主体不完整，无法建技能。']);
        }

        return redirect()
            ->route('shop.dev.skills.show', $product->id)
            ->with('success', '技能已创建（未上架）。接下来创建版本并上传技能包。');
    }

    public function edit(Request $request, int $id)
    {
        // 归属过滤在 skillSummary 的查询层完成；不属于我 → null → 404
        $product = $this->skills->skillSummary($this->profile($request), $id);

        if (! $product) {
            abort(404);
        }

        return view('shop::dev.skills.edit', compact('product'));
    }

    public function update(Request $request, int $id)
    {
        $payload = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'price'             => ['required', 'numeric', 'min:0'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string', 'max:5000'],
        ]);

        $profile = $this->profile($request);

        // 留痕要能回答"从什么改成了什么"，所以改之前先取一份旧值
        // （skillSummary 同样带归属过滤；拿不到就当 null，不阻断本次更新）
        $before = $this->skills->skillSummary($profile, $id);

        $product = $this->skills->updateSkill($profile, $id, $payload);

        if (! $product) {
            abort(404);
        }

        // 改价是"涉及钱的操作"，必须留痕（operation_log 的唯一入口是 OperationLogger）
        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.update',
            'product',
            $id,
            [
                // before 取改前的 flat 值，after 直接取本次提交值 —— 不依赖模型属性解析，读到的就是落库的那个数
                'before' => ['name' => $before->name ?? null, 'price' => $before->price ?? null],
                'after'  => ['name' => $payload['name'], 'price' => $payload['price']],
            ]
        );

        return redirect()
            ->route('shop.dev.skills.show', $id)
            ->with('success', '技能信息已更新。');
    }

    public function show(Request $request, int $id)
    {
        $profile = $this->profile($request);

        // 归属过滤在 skillSummary 的查询层完成
        $product = $this->skills->skillSummary($profile, $id);

        if (! $product) {
            // 他人的技能与不存在的技能**返回同一个 404** —— 状态码差异会变成枚举工具
            abort(404);
        }

        $versions = SkillVersion::query()
            ->where('product_id', $product->id)
            ->where('seller_id', $profile->seller_id)
            ->with('assets')
            ->orderByDesc('id')
            ->get();

        // 逐层闸门状态 —— 复用 `ReviewGate`（"能不能发布"的唯一定义处），
        // 门户里**不另写一份判定**：两份判定迟早会不一致，而开发者读到的必须是平台真正在执行的那份。
        // 未接入的层由 ReviewGate 如实报「未接入」，不会伪装成"已通过"。
        $gates = $versions->mapWithKeys(fn ($v) => [
            $v->id => $this->gate->evaluate($product->id, $v->id),
        ])->all();

        return view('shop::dev.skills.show', compact('product', 'versions', 'gates'));
    }

    public function storeVersion(Request $request, int $id)
    {
        $payload = $request->validate([
            'version'       => ['required', 'string', 'max:32'],
            'changelog'     => ['nullable', 'string', 'max:2000'],
            'install_guide' => ['nullable', 'string', 'max:5000'],
        ]);

        $version = $this->skills->createVersion($this->profile($request), $id, $payload);

        if (! $version) {
            abort(404);
        }

        return redirect()
            ->route('shop.dev.skills.show', $id)
            ->with('success', '版本已创建（draft）。请上传技能包后提交审核。');
    }

    public function upload(Request $request, int $versionId)
    {
        $payload = $request->validate([
            'carrier_type' => ['required', 'string', 'in:'.implode(',', SkillAsset::CARRIER_TYPES)],
            'entrypoint'   => ['nullable', 'string', 'max:191'],
            'sha256'       => ['nullable', 'string', 'size:64'],
            'file'         => ['nullable', 'file', 'max:'.$this->maxAssetKb()],
        ]);

        $result = $this->skills->uploadAsset(
            $this->profile($request),
            $versionId,
            $payload,
            $request->file('file')
        );

        // null = 版本不存在或不属于我
        if (! $result) {
            abort(404);
        }

        if (! $result->ok) {
            return back()->withErrors(['file' => $result->message]);
        }

        return back()->with('success', '技能包已上传。');
    }

    public function submit(Request $request, int $versionId)
    {
        $result = $this->skills->submitForReview($this->profile($request), $versionId);

        if (! $result) {
            abort(404);
        }

        if (! $result['ok']) {
            // 驳回理由要让开发者看得见（计划 C 组）
            return back()->withErrors([
                'submit' => $result['message'].(isset($result['errors']) && $result['errors']
                    ? ' '.implode(' / ', (array) $result['errors'])
                    : ''),
            ]);
        }

        return back()->with('success', $result['message']);
    }

    public function audits(Request $request)
    {
        return view('shop::dev.audits.index', [
            'records' => $this->skills->listAudits($this->profile($request)),
        ]);
    }

    /**
     * 撤回提审（任务卡 D-3 · D）—— 只做校验 + 调 service + 重定向。
     *
     * 归属过滤在 DeveloperSkillService::findOwnedVersion（查询层）；拿不到 → 404。
     */
    public function withdraw(Request $request, int $versionId)
    {
        $profile = $this->profile($request);

        $result = $this->skills->withdrawVersion($profile, $versionId);

        if (! $result) {
            abort(404);
        }

        if (! $result['ok']) {
            return back()->withErrors(['withdraw' => $result['message']]);
        }

        // 撤回是影响"这个版本在不在审核队列里"的动作，留痕（唯一入口 OperationLogger）
        $version = $result['version'];

        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.version.withdraw',
            'skill_version',
            $version->id,
            [
                'product_id' => $version->product_id,
                'version'    => $version->version,
                'from'       => SkillVersion::STATUS_IN_REVIEW,
                'to'         => SkillVersion::STATUS_DRAFT,
            ]
        );

        return back()->with('success', $result['message']);
    }

    /**
     * 下架技能（任务卡 D-3 · E）—— 只做校验 + 调 service + 重定向。
     *
     * 理由必填：这是影响**买家可见性**的动作，留痕要能解释"为什么下架"。
     * 归属过滤在 DeveloperSkillService::findOwnedSkill（查询层）；拿不到 → 404。
     *
     * ⚠ 刻意**没有** online（重新上架）入口：下架是单向的，重新上架走"运营发布"或
     *   "重新提审 → 发布"。不要顺手补一个 self-online 路由。
     */
    public function offline(Request $request, int $id)
    {
        $payload = $request->validate([
            'reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $profile = $this->profile($request);

        $result = $this->skills->takeOffline($profile, $id);

        if (! $result) {
            abort(404);
        }

        if (! $result['ok']) {
            return back()->withErrors(['offline' => $result['message']]);
        }

        $this->ops->log(
            OperationLog::ACTOR_SELLER,
            $profile->seller_id,
            'skill.offline',
            'product',
            $id,
            ['reason' => $payload['reason']]
        );

        return back()->with('success', $result['message']);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        // 由 `developer` 中间件放入（见 AuthenticateDeveloper）
        return $request->attributes->get('developer_profile');
    }

    /**
     * 上传上限与 internal.key 通道**共用同一个配置**，不另立一份，免得上限漂移。
     */
    protected function maxAssetKb(): int
    {
        return (int) config('skill_delivery.max_asset_kb', 51200);
    }
}
