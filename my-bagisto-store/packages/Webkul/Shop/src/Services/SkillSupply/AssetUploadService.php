<?php

namespace Webkul\Shop\Services\SkillSupply;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

/**
 * 交付物上传的**安全核** —— 从 `SkillAdminController::storeAsset` 抽出。
 *
 * 为什么抽出：上传是这条业务里最需要"只有一份实现"的地方 ——
 * sha256 复核、文件名清洗、私盘落盘、旧文件清理，任何一条被复制成两份，
 * 迟早有一份会漂移。internal.key 通道与开发者门户共用它。
 *
 * 安全红线（逐条落实，勿省）:
 *   - 落 private 盘，永不落 public
 *   - 服务端复核 sha256，不符直接拒绝
 *   - 文件名 basename + 正则清洗，防目录穿越
 *   - 不执行上传内容
 *
 * 本服务**不碰 HTTP**：校验已在控制器做完，这里只回 AssetUploadResult。
 */
class AssetUploadService
{
    /**
     * 存储/覆盖一个交付物。
     *
     * @param  array{
     *     carrier_type: string,
     *     sort_order?: int|null,
     *     entrypoint?: string|null,
     *     sha256?: string|null,
     *     payload?: array|null
     * }  $fields  已通过校验的请求字段
     */
    public function store(SkillVersion $version, array $fields, ?UploadedFile $file): AssetUploadResult
    {
        $carrierType = $fields['carrier_type'];
        $sortOrder = (int) ($fields['sort_order'] ?? 0);

        if ($carrierType === SkillAsset::CARRIER_FILE && ! $file) {
            return AssetUploadResult::fail('file 类型载体必须上传文件。', 422);
        }

        $attributes = [
            'seller_id'  => $version->seller_id,
            'entrypoint' => $fields['entrypoint'] ?? null,
            'payload'    => $fields['payload'] ?? null,
        ];

        if ($file) {
            $stored = $this->storeFile($version, $file, $fields['sha256'] ?? null);

            if (! $stored['ok']) {
                return $stored['result'];
            }

            $attributes = array_merge($attributes, $stored['attributes']);
        }

        // 幂等：同一 (version, carrier_type, sort_order) 重复上传则覆盖，并清掉旧文件
        $existing = SkillAsset::where('skill_version_id', $version->id)
            ->where('carrier_type', $carrierType)
            ->where('sort_order', $sortOrder)
            ->first();

        if ($existing && $existing->file_path && ($attributes['file_path'] ?? null) !== $existing->file_path) {
            Storage::disk(SkillAsset::disk())->delete($existing->file_path);
        }

        $asset = SkillAsset::updateOrCreate(
            [
                'skill_version_id' => $version->id,
                'carrier_type'     => $carrierType,
                'sort_order'       => $sortOrder,
            ],
            $attributes
        );

        return AssetUploadResult::ok($asset);
    }

    /**
     * 落盘单个文件并做安全检查。
     *
     * @return array{ok: bool, attributes?: array, result?: AssetUploadResult}
     */
    protected function storeFile(SkillVersion $version, UploadedFile $file, ?string $declaredSha256): array
    {
        if (! $file->isValid()) {
            return ['ok' => false, 'result' => AssetUploadResult::fail('文件上传失败,请重试。', 422)];
        }

        $realHash = hash_file('sha256', $file->getRealPath());

        if (! empty($declaredSha256) && ! hash_equals(strtolower($declaredSha256), $realHash)) {
            return ['ok' => false, 'result' => AssetUploadResult::fail(
                '文件校验失败:sha256 与声明不一致。',
                422,
                ['expected' => strtolower($declaredSha256), 'actual' => $realHash]
            )];
        }

        // basename 清洗，防目录穿越（客户端可控的文件名）
        $safeName = basename($file->getClientOriginalName());
        $safeName = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $safeName) ?: 'asset.bin';

        $directory = "skills/{$version->product_id}/{$version->version}";
        $path = $file->storeAs($directory, $safeName, SkillAsset::disk());

        if (! $path) {
            return ['ok' => false, 'result' => AssetUploadResult::fail('文件落盘失败。', 500)];
        }

        return [
            'ok'         => true,
            'attributes' => [
                'file_path' => $path,
                'file_name' => $safeName,
                'file_size' => $file->getSize(),
                'file_hash' => $realHash,
            ],
        ];
    }
}
