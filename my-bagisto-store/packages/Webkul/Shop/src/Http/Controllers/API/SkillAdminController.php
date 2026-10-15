<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\SkillPublisher;
use Webkul\Shop\Services\SkillSupply\AssetUploadService;

/**
 * 技能上传通道 —— 本项目第一个"商品写接口"。
 *
 * 为什么不复用 Bagisto 后台的商品 CRUD:那是 cookie + CSRF 的 session 表单流,
 * CLI / 合作方系统没法用;而且它表达不了 version / asset / license 这三样。
 *
 * 分两档,现在只做 Tier 0:
 *   Tier 0(本控制器)商品仍在后台表单建(全库才 11 个,一次建完),
 *                    只有"版本 + 交付物"走 API。
 *   Tier 1(后续)    再加 POST /skills 接受完整 manifest 一次性建商品 + 版本 + 资产。
 *
 * 鉴权由 internal.key 中间件负责(X-Internal-Key),不挂 api.customer ——
 * 那是买家身份,上传方是平台/合作方,两者模型不同。
 *
 * ⚠ 安全红线(逐条落实,别省):
 *   - 文件落 private 盘,永不落 public
 *   - 服务端复核 sha256,不符直接 422
 *   - 文件名做 basename 清洗,防目录穿越
 *   - carrier_type 走白名单枚举
 *   - 不执行上传内容
 */
class SkillAdminController extends APIController
{
    public function __construct(
        protected AssetUploadService $uploads,
        protected SkillPublisher $publisher,
    ) {}

    /**
     * 单文件大小上限(KB)。可被 config 覆盖。
     */
    protected function maxAssetKb(): int
    {
        return (int) config('skill_delivery.max_asset_kb', 51200); // 默认 50MB
    }

    /**
     * 建一个技能版本(draft)。
     */
    public function storeVersion(Request $request, int $productId): JsonResponse
    {
        $payload = $request->validate([
            'version'         => ['required', 'string', 'max:32'],
            'channel'         => ['nullable', 'string', 'in:stable,beta'],
            'license_mode'    => ['nullable', 'string', 'in:per_device,per_order,per_account,unlimited'],
            'max_activations' => ['nullable', 'integer', 'min:1'],
            'valid_days'      => ['nullable', 'integer', 'min:1'],
            'min_robot_version' => ['nullable', 'string', 'max:32'],
            'changelog'       => ['nullable', 'string'],
            // 安装指引:兜底交付通道靠它 —— 第三方成品机器人改不了固件,
            // 用户要照着这份说明把技能包装进机器
            'install_guide'   => ['nullable', 'string'],
            'seller_code'     => ['nullable', 'string', 'max:64'],
        ]);

        // 幂等:(product_id, version) 上有唯一键,重复提交返回已有那条而不是报错
        $version = SkillVersion::firstOrCreate(
            [
                'product_id' => $productId,
                'version'    => $payload['version'],
            ],
            [
                'seller_id'         => $this->resolveSellerId($payload['seller_code'] ?? null),
                'channel'           => $payload['channel'] ?? 'stable',
                'status'            => SkillVersion::STATUS_DRAFT,
                'license_mode'      => $payload['license_mode'] ?? 'per_device',
                'max_activations'   => $payload['max_activations'] ?? null,
                'valid_days'        => $payload['valid_days'] ?? null,
                'min_robot_version' => $payload['min_robot_version'] ?? null,
                'changelog'         => $payload['changelog'] ?? null,
                'install_guide'     => $payload['install_guide'] ?? null,
            ]
        );

        return response()->json([
            'message' => $version->wasRecentlyCreated ? '版本已创建(draft)。' : '版本已存在,返回现有记录。',
            'data'    => $this->formatVersion($version),
        ], $version->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * 给版本上传一个交付物。
     */
    public function storeAsset(Request $request, int $versionId): JsonResponse
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        $payload = $request->validate([
            'carrier_type' => ['required', 'string', 'in:'.implode(',', SkillAsset::CARRIER_TYPES)],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
            'entrypoint'   => ['nullable', 'string', 'max:191'],
            'sha256'       => ['nullable', 'string', 'size:64'],
            'payload'      => ['nullable', 'array'],
            'file'         => ['nullable', 'file', 'max:'.$this->maxAssetKb()],
        ]);

        // 安全核已抽到 AssetUploadService —— internal.key 通道与开发者门户共用同一份实现
        // (sha256 复核 / basename 清洗 / 私盘落盘 / 旧文件清理)
        $result = $this->uploads->store($version, $payload, $request->file('file'));

        if (! $result->ok) {
            // 失败原因由 AssetUploadService 给(缺文件 / 超体积 / sha256 不符 / 落盘失败),
            // 状态码也由它定 —— 这里只负责套上统一信封,不再自己拼 `message + extra`。
            return $this->fail(ApiErrorCode::SKILL_ASSET_UPLOAD_REJECTED, $result->message, $result->status, $result->extra);
        }

        return response()->json([
            'message' => $result->message,
            'data'    => $this->formatAsset($result->asset),
        ], $result->status);
    }

    /**
     * 更新版本的文案类字段(安装指引 / 变更日志)。
     *
     * 单独开这个口是因为 storeVersion 用的是 firstOrCreate(幂等),
     * 已存在的版本不会因为再次提交而更新 —— 安装指引又往往是在传完包之后才写。
     */
    public function updateVersion(Request $request, int $versionId): JsonResponse
    {
        $version = SkillVersion::find($versionId);

        if (! $version) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        $payload = $request->validate([
            'install_guide' => ['nullable', 'string'],
            'changelog'     => ['nullable', 'string'],
        ]);

        if (array_key_exists('install_guide', $payload)) {
            $version->install_guide = $payload['install_guide'];
        }

        if (array_key_exists('changelog', $payload)) {
            $version->changelog = $payload['changelog'];
        }

        $version->save();

        return response()->json([
            'message' => '已更新。',
            'data'    => $this->formatVersion($version->fresh('assets')),
        ]);
    }

    /**
     * 发布版本 —— 只有 published 的版本才会被下单锁定。
     */
    public function publish(Request $request, int $versionId): JsonResponse
    {
        $version = SkillVersion::with('assets')->find($versionId);

        if (! $version) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        // ⚠ 发布动作走**统一入口** SkillPublisher —— 闸门只此一处。
        //   此处原先没有闸门：未过审的技能可以经 internal.key 通道静默上架（发布旁路，
        //   2026-10-16 修）。别把这段逻辑再抄回来。
        $result = $this->publisher->publish($version);

        if (! $result['ok']) {
            // ⚠ 逐个码显式映射 —— **不能只用 default**。此前 default 兜 GATE_NOT_PASSED，
            //   一旦 SkillPublisher 新增理由码（如期的主体资格码），会被**静默误标**成"闸门没过"，
            //   语义全错且把排查引向审核台。default 只留作"契约有缺口"的兜底，给一个不撒谎的通用码。
            $code = match ($result['code']) {
                SkillPublisher::ERR_VERSION_ARCHIVED      => ApiErrorCode::VERSION_ARCHIVED,
                SkillPublisher::ERR_VERSION_HAS_NO_ASSET  => ApiErrorCode::VERSION_HAS_NO_ASSET,
                SkillPublisher::ERR_ASSET_FILE_MISSING    => ApiErrorCode::ASSET_FILE_MISSING,
                SkillPublisher::ERR_GATE_NOT_PASSED       => ApiErrorCode::GATE_NOT_PASSED,
                SkillPublisher::ERR_OWNER_NOT_VERIFIED    => ApiErrorCode::OWNER_NOT_VERIFIED,
                SkillPublisher::ERR_AGREEMENT_REQUIRED    => ApiErrorCode::AGREEMENT_REQUIRED,
                SkillPublisher::ERR_OWNER_PROFILE_MISSING => ApiErrorCode::OWNER_PROFILE_MISSING,
                default                                   => ApiErrorCode::SERVER_ERROR,
            };

            return $this->fail($code, $result['message'], 422);
        }

        return response()->json([
            'message' => $result['message'],
            'data'    => $this->formatVersion($result['version']),
        ]);
    }

    /**
     * 回读一个商品的全部版本与交付物 —— CI 幂等校验用。
     */
    public function manifest(int $productId): JsonResponse
    {
        $versions = SkillVersion::with('assets')
            ->where('product_id', $productId)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'product_id' => $productId,
                'versions'   => $versions->map(fn (SkillVersion $v) => $this->formatVersion($v))->values(),
            ],
        ]);
    }

    /**
     * seller_code → seller_id。平台自营返回 NULL。
     */
    protected function resolveSellerId(?string $code): ?int
    {
        if (! $code) {
            return null;
        }

        return \Webkul\Shop\Models\Seller::where('code', $code)->value('id');
    }

    /**
     * 版本输出。注意 assets 里**不含**磁盘路径 —— 付费内容的位置不能出 API。
     */
    protected function formatVersion(SkillVersion $version): array
    {
        return [
            'id'              => $version->id,
            'product_id'      => $version->product_id,
            'version'         => $version->version,
            'channel'         => $version->channel,
            'status'          => $version->status,
            'license_mode'    => $version->license_mode,
            'max_activations' => $version->max_activations,
            'valid_days'      => $version->valid_days,
            'changelog'       => $version->changelog,
            'install_guide'   => $version->install_guide,
            'released_at'     => optional($version->released_at)->toDateTimeString(),
            'assets'          => $version->relationLoaded('assets')
                ? $version->assets->map(fn (SkillAsset $a) => $this->formatAsset($a))->values()
                : [],
        ];
    }

    /**
     * 交付物输出 —— 刻意**不返回 file_path**。
     */
    protected function formatAsset(SkillAsset $asset): array
    {
        return [
            'id'           => $asset->id,
            'carrier_type' => $asset->carrier_type,
            'file_name'    => $asset->file_name,
            'file_size'    => $asset->file_size,
            'file_hash'    => $asset->file_hash,
            'entrypoint'   => $asset->entrypoint,
            'sort_order'   => $asset->sort_order,
            // 只给"文件在不在",不给路径
            'file_ready'   => $asset->isFile() ? $asset->fileExists() : true,
        ];
    }
}
