<?php

namespace Webkul\Shop\Services\SkillCompile\Builders;

use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\CarrierBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/**
 * 动作类载体构建器 —— v3.4 步骤 6。
 *
 * 中立动作(kind=motion) → 各机型关节空间,靠**动作重定向**。
 *
 * ⚠ 诚实边界:真正的重定向算法(把 BVH/SMPL-X 映射到某机型关节)是**独立工具/服务**,
 *   本构建器负责的是**编排与记录**:登记"该技能需要为哪些机型重定向""重定向配置是什么"。
 *   实际产物(artifact)由重定向服务产出后回填 —— 故 status=pending 直到产物就绪。
 *   这不是偷懒,是不把算法硬塞进平台层(保持架构健康)。
 *
 * ⚠ B6 fail-closed:登记**不等于放行**。下游重定向工具会照着这条登记干活,
 *   所以脏参数不能进入待办队列 —— 格式不在白名单、fps 离谱、文件名可疑、
 *   目标机型不是 GA,一律 `failed`(带机器码),而不是"先登记着,回头再说"。
 */
class MotionCarrierBuilder implements CarrierBuilder
{
    /** 编译期可识别的动作格式白名单 —— 与 `SkillPackageBuilder::SUPPORTED_MOTION_FORMATS` 同一套值。 */
    public const DEFAULT_ALLOWED_FORMATS = ['bvh', 'npz', 'csv', 'fbx', 'npy'];

    public const ERR_MANIFEST_INVALID = 'BUILD_MOTION_MANIFEST_INVALID';

    public const ERR_FORMAT_UNSUPPORTED = 'BUILD_MOTION_FORMAT_UNSUPPORTED';

    public const ERR_FPS_INVALID = 'BUILD_MOTION_FPS_INVALID';

    public const ERR_FILE_INVALID = 'BUILD_MOTION_FILE_INVALID';

    public const ERR_DURATION_INVALID = 'BUILD_MOTION_DURATION_INVALID';

    public const ERR_TARGET_NOT_GA = 'BUILD_TARGET_NOT_GA';

    public function supports(string $kind): bool
    {
        return in_array($kind, ['motion', 'composite'], true);
    }

    public function build(int $skillVersionId, array $manifest, string $targetModelCode, array $options = []): BuildResult
    {
        // 1. 目标机型必须是当前 GA 五机型之一。
        //    这里再拦一次（`SkillBuildService::compileWithCommand` 已有同类前置）：
        //    登记型构建走的是**另一条**调用路径（`SkillBuildService::compile`），
        //    那条路上没有 GA 闸 —— 少这一道，非 GA/已废止机型就能挂进待办队列。
        if (! in_array($targetModelCode, TargetProfileService::GA_TARGET_MODELS, true)) {
            return BuildResult::failed(
                "目标机型 {$targetModelCode} 不是当前 GA 机型（仅支持: ".implode('/ ', TargetProfileService::GA_TARGET_MODELS).'），拒绝登记动作重定向任务。',
                self::ERR_TARGET_NOT_GA
            );
        }

        $motion = $manifest['motion'] ?? null;

        if (! is_array($motion) || $motion === []) {
            return BuildResult::failed(
                'kind=motion 但缺少 motion 段(format/fps)，无法重定向。',
                self::ERR_MANIFEST_INVALID
            );
        }

        // 2. 格式白名单 —— 认不出来的格式不许"按原样转给下游工具"。
        $format = $this->normalizeFormat($motion['format'] ?? null);
        if ($format === null) {
            return BuildResult::failed(
                'motion.format 缺失或不在白名单（仅支持: '.implode('/', $this->allowedFormats()).'），拒绝登记。',
                self::ERR_FORMAT_UNSUPPORTED
            );
        }

        // 3. fps 必须是范围内的整数 —— 0 / 负数 / "abc" / 1e9 都不是"待人工确认",是坏输入。
        $fps = $this->normalizeFps($motion['fps'] ?? null);
        if ($fps === null) {
            $min = $this->minFps();
            $max = $this->maxFps();

            return BuildResult::failed(
                "motion.fps 必须是 {$min}~{$max} 之间的整数，实际为: ".$this->describe($motion['fps'] ?? null),
                self::ERR_FPS_INVALID
            );
        }

        // 4. 可选 file：必须是包内相对路径，且扩展名与声明格式一致（防"声明 bvh 实际是脚本"）。
        $file = $motion['file'] ?? null;
        if ($file !== null && $file !== '') {
            $fileError = $this->validateMotionFile((string) $file, $format);
            if ($fileError !== null) {
                return BuildResult::failed($fileError, self::ERR_FILE_INVALID);
            }

            $file = (string) $file;
        } else {
            $file = null;
        }

        // 5. 可选 duration_s：必须为正，且不得超过清单声明的安全上限。
        $duration = $motion['duration_s'] ?? null;
        if ($duration !== null) {
            $durationError = $this->validateDuration($duration, $manifest);
            if ($durationError !== null) {
                return BuildResult::failed($durationError, self::ERR_DURATION_INVALID);
            }
        }

        // 6. 源包溯源：有源资产就必须带上它的摘要，否则这条登记无法与任何字节对应。
        $source = $this->resolveSourceAsset($skillVersionId);

        // 7. 规范化重定向身份（JCS 规范化 → 同一份输入永远得到同一个摘要）。
        $identity = [
            'motion_format'   => $format,
            'fps'            => $fps,
            'file'           => $file,
            'duration_s'     => $duration === null ? null : (float) $duration,
            'target_model'   => $targetModelCode,
            'source_digest'  => $source['digest'] ?? null,
            'manifest_kind'  => (string) ($manifest['kind'] ?? 'motion'),
        ];

        $motionDigest = 'sha256:'.hash(
            'sha256',
            app(SigningService::class)->jcsCanonicalize($identity)
        );

        $retargetConf = [
            'source_format'         => $format,
            'fps'                   => $fps,
            'file'                  => $file,
            'duration_s'            => $identity['duration_s'],
            'target_model'          => $targetModelCode,
            'target_profile_digest' => app(TargetProfileService::class)->getDigest($targetModelCode),
            'motion_digest'         => $motionDigest,
            'source_asset_id'       => $source['asset_id'] ?? null,
            'source_digest'         => $source['digest'] ?? null,
            'retarget_engine'       => 'external', // 重定向工具标识,产物回填
            'note'                  => '待重定向工具产出各机型动作文件后回填 artifact。',
        ];

        return BuildResult::pending(
            carrierType: 'motion_data',
            retargetConf: $retargetConf,
            log: "已登记 {$targetModelCode} 的动作重定向任务（格式 {$format}，{$motionDigest}，待外部工具产出后回填产物）。",
        );
    }

    /**
     * @return array<int, string>
     */
    protected function allowedFormats(): array
    {
        $formats = config('skill_build.motion.allowed_formats', self::DEFAULT_ALLOWED_FORMATS);

        if (! is_array($formats) || $formats === []) {
            return self::DEFAULT_ALLOWED_FORMATS;
        }

        return array_values(array_filter(
            array_map(fn ($f) => is_string($f) ? strtolower(trim($f)) : null, $formats),
            fn ($f) => is_string($f) && $f !== ''
        ));
    }

    protected function normalizeFormat(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $format = strtolower(trim($raw));

        return in_array($format, $this->allowedFormats(), true) ? $format : null;
    }

    protected function normalizeFps(mixed $raw): ?int
    {
        if (is_int($raw)) {
            $fps = $raw;
        } elseif (is_string($raw) && preg_match('/^\d{1,4}$/', $raw) === 1) {
            $fps = (int) $raw;
        } else {
            return null;
        }

        $min = $this->minFps();
        $max = $this->maxFps();

        return ($fps >= $min && $fps <= $max) ? $fps : null;
    }

    /**
     * 动作文件路径校验：包内相对路径 + 扩展名与声明格式一致。
     */
    protected function validateMotionFile(string $file, string $format): ?string
    {
        if (strlen($file) > 512) {
            return 'motion.file 路径过长（超过 512 字符）。';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $file) === 1) {
            return 'motion.file 含控制字符，禁止。';
        }

        if (str_contains($file, '\\')
            || str_starts_with($file, '/')
            || preg_match('/^[A-Za-z]:/', $file) === 1
            || preg_match('#(^|/)\.\.(/|$)#', $file) === 1
            || preg_match('#^https?://#i', $file) === 1
            || preg_match('#(^|/)\.(/|$)#', $file) === 1) {
            return "motion.file '{$file}' 不是合法的包内相对路径（禁止绝对路径 / 盘符 / 反斜杠 / `..` / 外部 URL）。";
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($ext === '' || $ext !== $format) {
            return "motion.file '{$file}' 的扩展名 '{$ext}' 与声明格式 '{$format}' 不一致（禁止声明一套、实际另一套）。";
        }

        return null;
    }

    /**
     * 时长校验：正数，且不超过清单 safety.max_duration_s（若声明了）。
     */
    protected function validateDuration(mixed $raw, array $manifest): ?string
    {
        if (! is_int($raw) && ! is_float($raw) && ! (is_string($raw) && is_numeric($raw))) {
            return 'motion.duration_s 必须是数值，实际为: '.$this->describe($raw).'。';
        }

        $duration = (float) $raw;

        if (! is_finite($duration) || $duration <= 0.0) {
            return "motion.duration_s 必须大于 0，实际为: {$duration}。";
        }

        $max = (int) ($manifest['safety']['max_duration_s'] ?? 0);
        if ($max > 0 && $duration > $max) {
            return "motion.duration_s ({$duration}s) 超过清单 safety.max_duration_s ({$max}s)。";
        }

        return null;
    }

    /**
     * 源资产溯源（找不到不算错 —— 登记型构建历史上允许无源资产，但找到就必须带上摘要）。
     *
     * @return array{asset_id: int, digest: string}|null
     */
    protected function resolveSourceAsset(int $skillVersionId): ?array
    {
        $asset = SkillAsset::query()
            ->where('skill_version_id', $skillVersionId)
            ->where('carrier_type', 'file')
            ->where('asset_role', '!=', SkillAsset::ROLE_ARTIFACT)
            ->whereNotNull('file_hash')
            ->orderBy('id')
            ->first();

        if (! $asset) {
            return null;
        }

        return [
            'asset_id' => (int) $asset->id,
            'digest'   => 'sha256:'.strtolower((string) $asset->file_hash),
        ];
    }

    protected function minFps(): int
    {
        return max(1, (int) config('skill_build.motion.min_fps', 1));
    }

    protected function maxFps(): int
    {
        return max($this->minFps(), (int) config('skill_build.motion.max_fps', 240));
    }

    protected function describe(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default          => gettype($value),
        };
    }
}
