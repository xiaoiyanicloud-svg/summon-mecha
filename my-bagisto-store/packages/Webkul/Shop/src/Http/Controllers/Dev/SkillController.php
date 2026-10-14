<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;

/**
 * 开发者门户 · 我的技能 / 版本 / 传包 / 提审 —— v3.4 步骤 9。
 *
 * ⚠ 本控制器只做"校验 + 调 service + 渲染"。**归属校验一次都不在这里写** ——
 *   全部在 DeveloperSkillService 的 findOwned* 里，且一律是查询层过滤。
 *   控制器拿到的 null 一律映射成 404（不用 403，避免枚举他人资源 id）。
 */
class SkillController extends Controller
{
    public function __construct(protected DeveloperSkillService $skills) {}

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

        return view('shop::dev.skills.show', compact('product', 'versions'));
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
