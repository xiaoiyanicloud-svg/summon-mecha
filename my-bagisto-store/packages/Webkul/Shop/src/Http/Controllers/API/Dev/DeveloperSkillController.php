<?php

namespace Webkul\Shop\Http\Controllers\API\Dev;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\SkillSupply\AssetUploadService;

/**
 * `/api/dev/skills*` —— 开发者侧技能 CRUD / 版本 / 传包 / 提审的 JSON 合同。
 *
 * 路由与门户 Blade 走同一套 Service，所以行为一致、只有传输不同。
 *
 * 端点（路由名 shop.api.dev.*）：
 *   GET    /api/dev/skills
 *   POST   /api/dev/skills
 *   GET    /api/dev/skills/{skillId}
 *   POST   /api/dev/skills/{skillId}/versions
 *   POST   /api/dev/versions/{versionId}/assets
 *   POST   /api/dev/versions/{versionId}/submit
 *
 * ⚠ 提审端点按 **versionId** 收口（计划里写作 `skills/{id}/submit` 是简写）——
 *   真正要鉴权的对象是版本本身，用版本 id 更直接、也不会出现"技能对不上版本"的歧义。
 */
class DeveloperSkillController extends APIController
{
    public function __construct(protected DeveloperSkillService $skills) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->skills->listSkills($this->profile($request));

        return response()->json([
            'message' => 'ok',
            'data'    => $rows->map(fn ($r) => [
                'id'         => $r->id,
                'sku'        => $r->sku,
                'name'       => $r->name,
                'price'      => (float) $r->price,
                'status'     => (int) $r->status,
                'created_at' => $r->created_at,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name'              => ['required', 'string', 'max:191'],
            'price'             => ['required', 'numeric', 'min:0'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description'       => ['nullable', 'string', 'max:5000'],
        ]);

        $product = $this->skills->createSkill($this->profile($request), $payload);

        if (! $product) {
            return $this->fail(ApiErrorCode::DEVELOPER_SELLER_MISSING, '开发者主体不完整。', 403);
        }

        return response()->json([
            'message' => '技能已创建（未上架）。',
            'data'    => ['id' => $product->id, 'sku' => $product->sku],
        ], 201);
    }

    public function show(Request $request, int $skillId): JsonResponse
    {
        $profile = $this->profile($request);

        $product = $this->skills->skillSummary($profile, $skillId);

        if (! $product) {
            // 他人的与不存在的返回同一个 404 —— 状态码与 code 都一致，避免被用来枚举他人资源 id
            return $this->fail(ApiErrorCode::SKILL_NOT_FOUND, '技能不存在。', 404);
        }

        $versions = SkillVersion::query()
            ->where('product_id', $product->id)
            ->where('seller_id', $profile->seller_id)
            ->with('assets')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'id'       => $product->id,
                'sku'      => $product->sku,
                'name'     => $product->name,
                'price'    => (float) $product->price,
                'status'   => (int) $product->status,
                'versions' => $versions->map(fn (SkillVersion $v) => [
                    'id'      => $v->id,
                    'version' => $v->version,
                    'status'  => $v->status,
                    'channel' => $v->channel,
                    'assets'  => $v->assets->map(fn (SkillAsset $a) => [
                        'id'           => $a->id,
                        'carrier_type' => $a->carrier_type,
                        'file_name'    => $a->file_name,
                        'file_size'    => $a->file_size,
                        // 红线：绝不返回 file_path
                        'file_ready'   => $a->fileExists(),
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    public function storeVersion(Request $request, int $skillId): JsonResponse
    {
        $payload = $request->validate([
            'version'       => ['required', 'string', 'max:32'],
            'changelog'     => ['nullable', 'string', 'max:2000'],
            'install_guide' => ['nullable', 'string', 'max:5000'],
        ]);

        $version = $this->skills->createVersion($this->profile($request), $skillId, $payload);

        if (! $version) {
            return $this->fail(ApiErrorCode::SKILL_NOT_FOUND, '技能不存在。', 404);
        }

        return response()->json([
            'message' => '版本已创建（draft）。',
            'data'    => ['id' => $version->id, 'version' => $version->version, 'status' => $version->status],
        ], 201);
    }

    public function storeAsset(Request $request, int $versionId): JsonResponse
    {
        $payload = $request->validate([
            'carrier_type' => ['required', 'string', 'in:'.implode(',', SkillAsset::CARRIER_TYPES)],
            'entrypoint'   => ['nullable', 'string', 'max:191'],
            'sha256'       => ['nullable', 'string', 'size:64'],
            'payload'      => ['nullable', 'array'],
            'file'         => ['nullable', 'file', 'max:'.(int) config('skill_delivery.max_asset_kb', 51200)],
        ]);

        $result = $this->skills->uploadAsset(
            $this->profile($request),
            $versionId,
            $payload,
            $request->file('file')
        );

        if (! $result) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        if (! $result->ok) {
            // 与 internal.key 通道(SkillAdminController::storeAsset)共用 AssetUploadService,
            // 失败结构与错误码保持一致,免得同一个原因两条通道给前端两种形状。
            // ⚠ "已发布版本不许换包"要单独成码（与运营后台同一映射）：开发者据此知道该做的是
            //   发新版本，而不是反复调整这个文件。
            $code = $result->code === AssetUploadService::ERR_VERSION_PUBLISHED_IMMUTABLE
                ? ApiErrorCode::VERSION_PUBLISHED_IMMUTABLE
                : ApiErrorCode::SKILL_ASSET_UPLOAD_REJECTED;

            return $this->fail($code, $result->message, $result->status, $result->extra);
        }

        return response()->json([
            'message' => $result->message,
            'data'    => [
                'id'           => $result->asset->id,
                'carrier_type' => $result->asset->carrier_type,
                'file_name'    => $result->asset->file_name,
                'file_size'    => $result->asset->file_size,
                'file_ready'   => $result->asset->fileExists(),
            ],
        ], $result->status);
    }

    public function submit(Request $request, int $versionId): JsonResponse
    {
        $result = $this->skills->submitForReview($this->profile($request), $versionId);

        if (! $result) {
            return $this->fail(ApiErrorCode::VERSION_NOT_FOUND, '版本不存在。', 404);
        }

        return response()->json([
            'message' => $result['message'],
            'code'    => $result['code'] ?? null,
            'data'    => $result['data'] ?? ['errors' => $result['errors'] ?? []],
        ], $result['status']);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        return $request->attributes->get('developer_profile');
    }
}
