<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\SkillDelivery\SkillAssetAmbiguousException;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAssetResolver;

/**
 * 技能包限时下载端点（任务卡 S-1）—— 面向 Agent 的**唯一**取包通道。
 *
 * ── 为什么必须有它 ──
 *   在此之前平台**从不下发技能包**：`deploy_task.package_url` 恒为空，参考 Agent 只能走
 *   dry install 分支（"走通状态机但什么都没装"）。没有这条通道，"一键下发"就是按构造干跑。
 *
 * ── 两重控制，缺一不可 ──
 *   ① **Laravel 签名 URL**（`URL::temporarySignedRoute` + `signed` 中间件）：
 *      限时 + 防篡改 —— 改 `versionId` 或改 `expires` 都会让签名失配。
 *   ② **Agent 身份**（路由组上的 `agent.key` 中间件）：
 *      共享密钥 / per-device 凭据。**URL 泄露也拿不到包** —— 签名 URL 会出现在日志、
 *      代理与截图里，只靠"地址够隐蔽"不构成访问控制。
 *
 * ── 发之前先自检（不可省略） ──
 *   落盘的字节是"那一刻"的事实，而 `skill_assets.file_hash` 是上传时记下的哈希。两者
 *   此前**没有任何东西**在交付时比对过。本端点在实际输出前**实时复算 sha256** 与记录比对：
 *   不一致就**拒绝输出**并明确报错（PACKAGE_INTEGRITY_MISMATCH）—— 宁可让这次下发失败，
 *   也绝不把"跟记录对不上的字节"发给一台会真的把它跑起来的机器人。
 *
 * ── 安全红线 ──
 *   · 文件路径**只由服务端**决定（按 versionId 查库），客户端给不了路径；
 *   · 全程`SkillAsset::disk()` 私盘流式读出，**没有公开 URL**，也**不回泄** `file_path`。
 */
class AgentPackageController extends APIController
{
    public function __construct(protected SkillDeliveryAssetResolver $assets) {}

    /**
     * 下载某版本的一个文件类交付物。
     *
     * 多交付物的版本：本端点一次只交付**一个**文件。解析顺序为
     * `?asset_id=` → `?carrier_type=` / `?sort_order=` → 已成功构建的机型产物 →
     * 唯一文件类交付物 → 声明 SHA 唯一命中；仍无法唯一确定时返回 409
     * （SKILL_ASSET_AMBIGUOUS），不静默挑一个。**不自己发明打包格式** ——
     * 把多个交付物合成一个包属编译层，不在下发这一层。
     */
    public function download(Request $request, int $versionId)
    {
        try {
            $asset = $this->resolveAsset($request, $versionId);
        } catch (SkillAssetAmbiguousException $e) {
            return $this->fail(ApiErrorCode::SKILL_ASSET_AMBIGUOUS, $e->getMessage(), 409, [
                'data' => [
                    'version_id' => $versionId,
                    'asset_ids'  => $e->assetIds,
                ],
            ]);
        }

        if (! $asset) {
            // 版本不存在、或该版本没有可下载的文件类交付物 —— 都归到这里（不对客户端区分
            // "版本不存在"与"版本没有包"：它没有需要据此分支的处置）。
            $message = $request->query('asset_id') !== null
                ? '指定的交付物不存在、不属于该版本，或不是文件类交付物。'
                : '该版本没有可下载的文件类交付物。';

            return $this->fail(ApiErrorCode::SKILL_ASSET_NOT_FOUND, $message, 404);
        }

        if (! $asset->fileExists()) {
            // 有记录、东西不在盘上 —— 需人工介入，不是客户端能纠正的错。
            return $this->fail(ApiErrorCode::SKILL_ASSET_FILE_MISSING, '交付物文件不存在，请联系平台。', 404);
        }

        // ── 自检：实时复算的 sha256 必须与记录一致 ──
        $actual = $this->sha256OfStoredFile($asset);
        $recorded = (string) $asset->file_hash;

        if ($actual === null || $recorded === '' || ! hash_equals($recorded, $actual)) {
            // 记录为空（历史行没 hash）也拒绝：**无法证明**这一份就是该发的那一份。
            Log::error('技能包下载被拒：落盘字节与记录哈希不一致', [
                'version_id' => $versionId,
                'asset_id'   => $asset->id,
                'recorded'   => $recorded,
                'actual'     => $actual,
            ]);

            return $this->fail(
                ApiErrorCode::PACKAGE_INTEGRITY_MISMATCH,
                '交付物与记录的哈希不一致，已拒绝下发（请联系平台核查该版本的交付物）。',
                409,
                ['data' => ['version_id' => $versionId, 'asset_id' => $asset->id]],
            );
        }

        // X4 · 取包留痕：技能包是私有交付物，"谁在何时取了哪个包"是事后追溯的硬需求。
        Log::info('技能包下发', [
            'version_id' => $versionId,
            'asset_id'   => $asset->id,
            'did'        => $request->attributes->get('agent.did'),
            'bytes'      => $asset->file_size,
        ]);

        // 流式读出，与 C 端鉴权下载（SkillController::download）同一落盘读取方式。
        return Storage::disk(SkillAsset::disk())->download(
            $asset->file_path,
            $asset->file_name ?: basename((string) $asset->file_path)
        );
    }

    /**
     * 定位要交付的那个交付物 —— 命名与选择规则见类注释。
     *
     * 选择顺序由 {@see SkillDeliveryAssetResolver} 统一定义（显式 asset_id →
     * carrier_type/sort_order → 机型产物 → 唯一文件）。多文件且无法唯一确定时抛
     * {@see SkillAssetAmbiguousException}，由调用方转 409 —— 绝不默认取排序第一个。
     */
    protected function resolveAsset(Request $request, int $versionId): ?SkillAsset
    {
        $assetId = $request->query('asset_id');
        $sortOrder = $request->query('sort_order');

        return $this->assets->resolveForVersion(
            $versionId,
            null,
            $assetId !== null ? (int) $assetId : null,
            $request->query('carrier_type') !== null ? (string) $request->query('carrier_type') : null,
            $sortOrder !== null ? (int) $sortOrder : null,
        );
    }

    /**
     * 实时复算私盘上那个文件的 sha256（流式，不整包读进内存）。
     *
     * 用 `readStream` + `hash_update_stream` 而非 `hash_file(path)`：后者依赖本地路径，
     * 一旦 `skill_delivery.asset_disk` 切到对象存储就取不到 `path()`。这里与磁盘形态无关。
     *
     * @return string|null  取不到流时返回 null（调用方据此拒绝输出）
     */
    protected function sha256OfStoredFile(SkillAsset $asset): ?string
    {
        $stream = Storage::disk(SkillAsset::disk())->readStream((string) $asset->file_path);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
