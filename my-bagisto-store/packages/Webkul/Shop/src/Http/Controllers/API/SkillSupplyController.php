<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillCompatibilityEvidence;
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
     *
     * ⚠ 本端点报的是**登记**结果,不是"编译完成"(D-9 口径更正)。`skill_build` 记的是
     *   编排意图,真正的产物需外部重定向工具产出或人工回填 —— 平台侧当前没有生产者。
     *   三种结果分开如实说,不再一律 200 + "编译已登记。":
     *     · 全部机型 failed → **422** + `SKILL_COMPILE_NOTHING_REGISTERED`
     *       (kind 不支持 / 清单缺段是**调用方可修正**的输入问题,HTTP 层应当看得见);
     *     · 部分 failed     → 200,message **点名**哪些机型没登记、为什么;
     *     · 有 pending       → 200,message 如实说"待人工/外部产出,产物就绪前不可交付"。
     */
    public function compile(Request $request, int $versionId): JsonResponse
    {
        $payload = $request->validate([
            'manifest'      => ['required', 'array'],
            'target_models' => ['required', 'array', 'min:1'],
            'target_models.*' => ['string'],
        ]);

        $results = $this->builder->compile($versionId, $payload['manifest'], $payload['target_models']);

        $total = count($results);
        $failed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'failed'));
        $pending = array_values(array_filter($results, fn (array $r) => $r['status'] === 'pending'));

        // 全部失败:一件都没登记上 —— 调用方可修正(换 kind / 补齐清单段),用 422 + 码,
        // 而不是 200 里塞个"编译已登记"(后者让 HTTP 层看不见失败)。
        if ($failed && count($failed) === $total) {
            return $this->fail(
                ApiErrorCode::SKILL_COMPILE_NOTHING_REGISTERED,
                sprintf(
                    '没有任何机型登记成功(共 %d 个目标机型全部失败):%s',
                    $total,
                    $this->failedModelDetails($failed)
                ),
                422,
                ['data' => $results],
            );
        }

        // 部分失败:登记到了一部分,但必须**点名**没登记上的那些与原因 —— 不允许笼统"编译已登记"。
        if ($failed) {
            return response()->json([
                'message' => sprintf(
                    '已登记 %d/%d 个机型;另有 %d 个机型未登记(%s)。',
                    $total - count($failed),
                    $total,
                    count($failed),
                    $this->failedModelDetails($failed)
                ),
                'data' => $results,
            ]);
        }

        // 全部(或部分)pending:如实说清是"已登记为待产出",不是"编译完成"。
        if ($pending) {
            return response()->json([
                'message' => sprintf(
                    '已登记 %d 个机型的产出任务:待人工/外部产出,产物就绪前不可交付。',
                    count($pending)
                ),
                'data' => $results,
            ]);
        }

        // 走到这里说明有 success —— 平台侧当前没有生产者(见 SkillBuild 类注释),故现实中
        // 不可达;保留这个真话分支,等真构建器接进来即可用,不必改本端点。
        return response()->json([
            'message' => sprintf('编译完成:%d 个机型已产出产物。', $total),
            'data' => $results,
        ]);
    }

    /**
     * 失败机型的"model(原因)"明细串 —— 全失败与部分失败共用,保证两处措辞一致。
     *
     * @param  array<int, array{model: string, status: string, error: ?string}>  $failed
     */
    protected function failedModelDetails(array $failed): string
    {
        return implode('；', array_map(
            fn (array $r) => ($r['model'] ?? '?').'('.(($r['error'] ?? '') !== '' ? $r['error'] : '未知原因').')',
            $failed
        ));
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
     * 适配证据 · 写入(A3 第 3 环)。
     *
     * 一条记录 = "某版本在某机型/固件/SDK/Agent 组合上被验证过"。三轴分开记,互不替代:
     *   source        谁报的(device / simulation / static / manual)
     *   evidence_type 验到哪一步(install / runtime / lab_test / manual_review)
     *   result        结论(match / mismatch / inconclusive)
     *
     * ⚠ 写入的是**事实记录**,不是"认证":`source=device` 只代表上报来源,平台无法核验
     *   上报者真的是那台机器;`static/simulation/manual` 更不得被读成"真机已验证"
     *   (读侧的降级由 {@see \Webkul\Shop\Services\SkillDelivery\CompatibilityService} 负责)。
     *
     * ⚠ 证据必须钉住当时的产物:`asset_id` 与 `asset_hash` **至少给一个**
     *   (都缺 = 不知道验证的是哪份字节,不接受)。给了 `asset_id` 就必须属于该版本、
     *   且必须是**文件类**载体（`carrier_type=file`）并带非空 sha256；
     *   `asset_hash` 一律以库内 `skill_assets.file_hash` 为准。调用方显式给的哈希只用于
     *   **核对**,不一致直接拒绝 —— 否则事后可以把证据改挂到另一份字节上,"验证过"就成了空话。
     */
    public function storeCompatibilityEvidence(Request $request, int $versionId): JsonResponse
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        $payload = $request->validate([
            'model_code'          => ['required', 'string', 'max:64'],
            'source'              => ['required', 'string', 'in:'.implode(',', SkillCompatibilityEvidence::SOURCES)],
            'evidence_type'       => ['required', 'string', 'in:'.implode(',', SkillCompatibilityEvidence::EVIDENCE_TYPES)],
            'result'              => ['required', 'string', 'in:'.implode(',', SkillCompatibilityEvidence::RESULTS)],
            'asset_id'            => ['nullable', 'integer'],
            'asset_hash'          => ['nullable', 'string', 'max:64'],
            'device_did'          => ['nullable', 'string', 'max:64'],
            'development_version' => ['nullable', 'string', 'max:64'],
            'firmware_version'    => ['nullable', 'string', 'max:64'],
            'sdk_version'         => ['nullable', 'string', 'max:64'],
            'agent_version'       => ['nullable', 'string', 'max:32'],
            'carrier_type'        => ['nullable', 'string', 'max:32'],
            'summary'             => ['nullable', 'string', 'max:1000'],
            'verified_by'         => ['nullable', 'string', 'max:64'],
            'observed_at'         => ['nullable', 'date'],
        ]);

        if (empty($payload['asset_id']) && empty($payload['asset_hash'])) {
            return $this->fail(
                ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                '证据必须钉住产物：请提供 asset_id 或 asset_hash（至少一个）。',
                422,
            );
        }

        $asset = null;

        if (! empty($payload['asset_id'])) {
            $asset = SkillAsset::query()
                ->where('skill_version_id', $versionId)
                ->whereKey((int) $payload['asset_id'])
                ->first();

            if (! $asset) {
                return $this->fail(
                    ApiErrorCode::SKILL_ASSET_NOT_FOUND,
                    '指定交付物不存在或不属于该版本,不能作为该版本的适配证据。',
                    422,
                );
            }
        } elseif (! empty($payload['asset_hash'])) {
            // 只给哈希:按"该版本内唯一命中"定位。命中 0 条 = 声称验证过的字节在这版里不存在;
            // 命中多条 = 调用方必须用 asset_id 说清是哪一份,不能由平台替他猜。
            $matched = SkillAsset::fileAssetsForVersion($versionId)
                ->filter(fn (SkillAsset $a) => is_string($a->file_hash) && $a->file_hash !== ''
                    && hash_equals((string) $payload['asset_hash'], $a->file_hash))
                ->values();

            if ($matched->count() !== 1) {
                return $this->fail(
                    ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                    $matched->isEmpty()
                        ? 'asset_hash 在该版本交付物中不存在。'
                        : 'asset_hash 在该版本命中多个交付物,请改用 asset_id 指明。',
                    422,
                );
            }

            $asset = $matched->first();
        }

        // 只有"按字节定义"的文件类交付物才能作为安装/运行验证的对象。
        // cloud_config / remote_service 等没有字节的载体，不能拿来声称某个包已验证。
        if ($asset && ! $asset->isFile()) {
            return $this->fail(
                ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                '只有文件类交付物（carrier_type=file）可以作为适配证据。',
                422,
            );
        }

        if ($asset && (! is_string($asset->file_hash) || $asset->file_hash === '')) {
            return $this->fail(
                ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                '该交付物缺少 sha256，无法证明验证的是哪份字节，不能作为适配证据。',
                422,
            );
        }

        // 载体类型只能来自产物本身；调用方给了不一致的值就直接拒绝，不静默改写。
        if ($asset
            && ! empty($payload['carrier_type'])
            && (string) $payload['carrier_type'] !== (string) $asset->carrier_type) {
            return $this->fail(
                ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                'carrier_type 与 asset_id 指向的交付物不一致；请省略该字段或填写 '.$asset->carrier_type.'。',
                422,
            );
        }

        // 显式哈希与库内不一致 → 拒绝。绝不"以调用方为准"覆盖库内事实。
        if ($asset && ! empty($payload['asset_hash'])) {
            $stored = is_string($asset->file_hash) ? $asset->file_hash : '';

            if ($stored === '' || ! hash_equals($stored, (string) $payload['asset_hash'])) {
                return $this->fail(
                    ApiErrorCode::SKILL_COMPATIBILITY_EVIDENCE_INVALID,
                    'asset_hash 与该交付物在库内记录的哈希不一致。',
                    422,
                );
            }
        }

        $evidence = SkillCompatibilityEvidence::create([
            'skill_version_id'    => $versionId,
            'product_id'          => $version->product_id,
            'device_did'          => $payload['device_did'] ?? null,
            'model_code'          => $payload['model_code'],
            'development_version' => $payload['development_version'] ?? null,
            'firmware_version'    => $payload['firmware_version'] ?? null,
            'sdk_version'         => $payload['sdk_version'] ?? null,
            'agent_version'       => $payload['agent_version'] ?? null,
            'carrier_type'        => $asset->carrier_type,
            'asset_id'            => $asset?->id,
            'asset_hash'          => $asset?->file_hash,
            'source'              => $payload['source'],
            'evidence_type'       => $payload['evidence_type'],
            'result'              => $payload['result'],
            'summary'             => $payload['summary'] ?? null,
            'verified_by'         => $payload['verified_by'] ?? null,
            'observed_at'         => $payload['observed_at'] ?? now(),
        ]);

        return response()->json([
            'message' => '已记录适配证据。',
            'data'    => $this->serializeEvidence($evidence),
        ], 201);
    }

    /**
     * 适配证据 · 查询(按观测时间倒序)。
     *
     * 同时回**声明**(manifest 的 compatibility)与**证据**:两者是不同性质的东西,
     * 调用方与前端不得把它们合并成一个"已适配"结论。
     */
    public function compatibilityEvidence(int $versionId): JsonResponse
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        $rows = $version->compatibilityEvidence()->get();

        return response()->json([
            'data' => [
                'version_id'     => (int) $version->id,
                'product_id'     => (int) $version->product_id,
                'compatibility'  => $version->compatibility,
                'evidence_count' => $rows->count(),
                'evidence'       => $rows->map(fn (SkillCompatibilityEvidence $e) => $this->serializeEvidence($e))->values(),
            ],
        ]);
    }

    /**
     * 单条证据的对外形状 —— 三轴各自带人话标签,不替调用方合成结论。
     *
     * @return array<string, mixed>
     */
    protected function serializeEvidence(SkillCompatibilityEvidence $e): array
    {
        return [
            'id'                  => (int) $e->id,
            'skill_version_id'    => (int) $e->skill_version_id,
            'product_id'          => $e->product_id !== null ? (int) $e->product_id : null,
            'device_did'          => $e->device_did,
            'model_code'          => $e->model_code,
            'development_version' => $e->development_version,
            'firmware_version'    => $e->firmware_version,
            'sdk_version'         => $e->sdk_version,
            'agent_version'       => $e->agent_version,
            'carrier_type'        => $e->carrier_type,
            'asset_id'            => $e->asset_id !== null ? (int) $e->asset_id : null,
            'asset_hash'          => $e->asset_hash,
            'source'              => $e->source,
            'source_label'        => $e->sourceLabel(),
            'evidence_type'       => $e->evidence_type,
            'evidence_type_label' => $e->evidenceTypeLabel(),
            'result'              => $e->result,
            'result_label'        => $e->resultLabel(),
            'summary'             => $e->summary,
            'verified_by'         => $e->verified_by,
            'observed_at'         => $e->observed_at?->toIso8601String(),
            'created_at'          => $e->created_at?->toIso8601String(),
        ];
    }

    /**
     * 技能熔断(v3.4 步骤 8,§4.19)。
     *
     * ⚠ 生效范围(S-5 校准后的口径):**对新下发即时阻断**;对已装设备只在
     *   **该设备下次上线**时经 agent 卸载/回滚;**人工通道安装的当前无法自动卸载**。
     *   返回的 `data` 把三类数字分列(forced_online / forced_offline /
     *   unable_auto_uninstall),**不**把"建了几条任务"笼统报成"已全部禁用"。
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

        // 提示语如实说清生效范围,别让调用方以为"一键已全停"。
        $message = '已熔断:新下发即时阻断。';

        if ($res['forced_tasks'] > 0 || $res['unable_auto_uninstall'] > 0) {
            $message .= sprintf(
                '已装设备:在线即时卸载/回滚 %d 台,离线待下次上线 %d 台,无法自动卸载 %d 台。',
                $res['forced_online'],
                $res['forced_offline'],
                $res['unable_auto_uninstall'],
            );
        }

        return response()->json(['message' => $message, 'data' => $res]);
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
