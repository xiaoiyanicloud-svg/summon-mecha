<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;
use Webkul\Shop\Services\SkillImport\SkillImportService;
use Webkul\Shop\Services\SkillPackage\PackageInspector;

/**
 * 技能供给侧管控接口(v3.4 步骤 6)—— 挂在 internal.key 下(开发者门户是 P1)。
 *
 * 覆盖:包检查 → 导入 → 编译(转换层) → 提审(三层)。
 * 只做编排,逻辑都在各自 Service 里。
 */
class SkillSupplyController extends APIController
{
    public function __construct(
        protected PackageInspector $inspector,
        protected SkillImportService $importer,
        protected SkillBuildService $builder,
        protected AuditService $audit,
        protected SkillVersionState $states,
    ) {}

    /**
     * 检查一个 .skill 包(上传 ZIP)。
     */
    public function inspect(Request $request): JsonResponse
    {
        $request->validate(['package' => ['required', 'file']]);

        $path = $request->file('package')->getRealPath();
        $result = $this->inspector->inspect($path);

        $data = [
            'ok'       => $result['ok'],
            'errors'   => $result['errors'],
            'warnings' => $result['warnings'],
            'manifest' => $result['manifest'],
            'files'    => array_slice($result['files'], 0, 50),
        ];

        if (! $result['ok']) {
            // 包不合规是**调用方可修正**的输入问题 → 422 + 码,而不是 200 里塞个 ok:false
            // (后者会让脚本消费者必须解析 body 才知道失败,HTTP 层看不见)。
            return $this->fail(ApiErrorCode::SKILL_PACKAGE_INVALID, '包检查未通过。', 422, ['data' => $data]);
        }

        // ⚠ data 里仍保留 ok:true —— 成功体不动(X1 只统一失败结构)
        return response()->json(['message' => '包检查通过。', 'data' => $data]);
    }

    /**
     * 导入他平台/动捕技能。
     */
    public function import(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'input'       => ['required', 'array'],
            'seller_code' => ['nullable', 'string', 'max:64'],
            'source_ref'  => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->importer->import($payload['input'], null, $payload['source_ref'] ?? null);

        if (! $result['ok']) {
            return $this->fail(ApiErrorCode::SKILL_IMPORT_FAILED, (string) ($result['message'] ?? '导入失败。'), 422, [
                'result' => $result['result'] ?? null,
            ]);
        }

        return response()->json($result, 201);
    }

    /**
     * 编译(转换层):中立清单 → 目标机型载体。
     */
    public function compile(Request $request, int $versionId): JsonResponse
    {
        $payload = $request->validate([
            'manifest'      => ['required', 'array'],
            'target_models' => ['required', 'array', 'min:1'],
            'target_models.*' => ['string'],
        ]);

        $results = $this->builder->compile($versionId, $payload['manifest'], $payload['target_models']);

        return response()->json(['message' => '编译已登记。', 'data' => $results]);
    }

    /**
     * 提审:跑 L0 机审。
     */
    public function submit(Request $request, int $versionId): JsonResponse
    {
        $payload = $request->validate([
            'skill_id' => ['required', 'integer'],
            'manifest' => ['required', 'array'],
        ]);

        // 状态前置：只有「草稿」/「被驳回」可提审。
        // internal.key 通道同样要拦 —— 否则已发布版本可经此**重复提审**，
        // 写出一条 audit_record 却永远不进审核队列（静默黑洞）。
        // 版本不存在时不拦（沿用 submitLayer0 对不存在版本的原行为，不改变既有语义）。
        $version = SkillVersion::find($versionId);

        if ($version && ! $this->states->canSubmit($version)) {
            return $this->fail(
                ApiErrorCode::VERSION_NOT_SUBMITTABLE,
                '只有「草稿」或「被驳回」的版本可以提交审核。',
                422
            );
        }

        $result = $this->audit->submitLayer0($payload['skill_id'], $versionId, $payload['manifest']);

        if ($result['result'] !== 1) {
            // L0 机审未过 = 制品有可定位的问题(缺字段 / 声明与实物不符),调用方需改了再提
            return $this->fail(ApiErrorCode::SKILL_L0_AUDIT_FAILED, 'L0 机审未通过。', 422, ['data' => $result]);
        }

        return response()->json(['message' => 'L0 机审通过。', 'data' => $result]);
    }

    /**
     * 审核定论:L1 仿真 / L2 人审。
     *
     * ⚠ **本通道拒收 `auditor_id`**（此前是可传的，那是个漏洞）。
     *
     *   本通道挂的是 internal.key —— 一把**单一的平台共享密钥**，它不代表任何具体的人。
     *   所以请求体里写谁就是谁，服务端没有任何办法核验。而 Admin 审核台把该字段
     *   渲染成 `#<id>`，看上去就成"某位真人审过这一版"了 —— 这正是本项目最忌讳的
     *   "显示假追溯"：出事要复盘是谁放行的，拿到的是一个可以随便填的数字。
     *
     *   现在改成：**只有平台控制台（登录身份写入）才能产生非空 auditor_id**。
     *   于是 `auditor_id 非空` 本身就成了一条可依赖的事实。
     *   L2 人审请走 `/admin/catalog/skill-reviews`；本通道用于提交 L1 仿真结果。
     *
     *   注：Admin 详情页的「审核人」列也据此改成了两种措辞，见
     *   `catalog/skill-reviews/show.blade.php`。
     */
    public function review(Request $request, int $versionId): JsonResponse
    {
        if ($request->filled('auditor_id')) {
            return $this->fail(ApiErrorCode::AUDITOR_ID_NOT_ACCEPTED_HERE, '本通道不接受 auditor_id：internal.key 是单一平台密钥，不代表具体的人，'
                    .'因此这里传入的"审核人"无法核验。写入一个无法核验的审核人，'
                    .'会让审核记录看上去像是某位真人审过 —— 请改在平台控制台'
                    .'（技能审核台）以登录身份做 L2 人审；本通道用于提交 L1 仿真结果。', 422);
        }

        $payload = $request->validate([
            'skill_id' => ['required', 'integer'],
            'layer'    => ['required', 'integer', 'in:2,3'],
            'result'   => ['required', 'integer', 'in:1,2,3'],
            'reason_category' => ['nullable', 'string', 'max:64'],
            'reason_detail'   => ['nullable', 'string', 'max:2000'],
            'suggestion'      => ['nullable', 'string', 'max:2000'],
            'sim'             => ['nullable', 'array'],
        ]);

        if ($payload['layer'] == \Webkul\Shop\Models\AuditRecord::LAYER_SIMULATION) {
            $result = $this->audit->recordLayer1($payload['skill_id'], $versionId, $payload['sim'] ?? ['ran' => false]);
        } else {
            $result = $this->audit->recordLayer2(
                $payload['skill_id'],
                $versionId,
                (int) $payload['result'],
                // 恒为 null —— 本通道没有可核验的人。见方法头注释。
                null,
                $payload['reason_category'] ?? null,
                $payload['reason_detail'] ?? null,
                $payload['suggestion'] ?? null,
            );
        }

        return response()->json(['message' => '已记录审核结果。', 'data' => $result]);
    }

    /**
     * 查某版本的编译记录。
     */
    public function builds(int $versionId): JsonResponse
    {
        $rows = SkillBuild::query()->where('skill_version_id', $versionId)->get();

        return response()->json([
            'data' => $rows->map(fn (SkillBuild $b) => [
                'id'                => $b->id,
                'target_model_code' => $b->target_model_code,
                'target_agent_type' => $b->target_agent_type,
                'status'            => $b->status,
                'carrier_type'      => $b->carrier_type,
                'error'             => $b->error,
            ])->values(),
        ]);
    }

    /**
     * 技能熔断(v3.4 步骤 8,§4.19):阻断新下发 + 可选强制卸载/回滚已装设备。
     */
    public function block(Request $request, int $skillId): JsonResponse
    {
        $payload = $request->validate([
            'action'   => ['nullable', 'string', 'in:block_new,block_all,force_uninstall,force_rollback'],
            'reason'   => ['nullable', 'string', 'max:500'],
            'severity' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        $res = app(\Webkul\Shop\Services\Deploy\KillSwitchService::class)->engage(
            $skillId,
            $payload['action'] ?? 'block_all',
            $payload['reason'] ?? '',
            $payload['severity'] ?? 'high',
            // P10:操作者不再写死 'admin' —— 调用方可经 X-Internal-Operator 自报身份,
            // 不自报则记 'internal'(见 APIController::internalOperator 的诚实边界说明)。
            $this->internalOperator($request),
            $request->ip(),
        );

        return response()->json(['message' => '已熔断。', 'data' => $res]);
    }

    /**
     * 解除熔断。
     */
    public function lift(Request $request, int $skillId): JsonResponse
    {
        $ok = app(\Webkul\Shop\Services\Deploy\KillSwitchService::class)->lift(
            $skillId,
            $this->internalOperator($request),
            $request->ip(),
        );

        if (! $ok) {
            return $this->fail(ApiErrorCode::KILL_SWITCH_NOT_ENGAGED, '无生效中的熔断。', 404);
        }

        return response()->json(['message' => '已解除熔断。']);
    }
}
