<?php

namespace Webkul\Shop\Services\SkillCompile;

/**
 * 工具链指纹（WBS B5.5）。
 *
 * 目的不是"记一堆版本号好看"，而是回答：**同一个 build_key 的产物，是不是同一套工具产出的？**
 * 没有这行记录，两次构建结果不同就只能猜"大概是环境问题"。
 *
 * 诚实边界：平台当前**没有独立构建镜像/容器**，所以 `image_digest` 必须是 null、
 * 状态必须是 `NOT_RUN`。写一个假的 digest 比不写更糟 —— 那会让"可复现"看起来已经成立。
 *
 * ── 独立构建镜像摘要（第十批 T5）──
 *
 *   开关默认关闭：只有外部工具（`tools/build-image.ps1`）**真实构建并 inspect 出 digest**
 *   之后，通过环境变量 `SKILL_BUILD_IMAGE_DIGEST` 注入，才会记为 `PROBED`。
 *   本类**从不自己计算或猜测** digest —— 拿不到就是 `NOT_RUN`，格式不对就是 `INVALID_DIGEST`。
 *
 *   键名兼容：`container_image_digest` / `container_image_status` 是第八批已有的键，
 *   继续保留（下游 Run 历史已在用）；`image_digest` / `image_status` / `image_ref` 是
 *   本批新增的规范键，二者始终同值。
 */
final class ToolchainFingerprint
{
    public const IMAGE_ENV_DIGEST = 'SKILL_BUILD_IMAGE_DIGEST';

    public const IMAGE_ENV_REF = 'SKILL_BUILD_IMAGE_REF';

    public const STATUS_NOT_RUN = 'NOT_RUN';

    public const STATUS_PROBED = 'PROBED';

    /** 注入值格式不对 —— 明确拒绝，绝不把它当 digest 落库。 */
    public const STATUS_INVALID_DIGEST = 'INVALID_DIGEST';

    public static function current(): array
    {
        $image = self::image();

        return [
            'php_version'            => PHP_VERSION,
            'php_sapi'               => PHP_SAPI,
            'os_family'              => PHP_OS_FAMILY,
            'builders'               => self::builders(),
            'image_ref'              => $image['ref'],
            'image_digest'           => $image['digest'],
            'image_status'           => $image['status'],
            // 第八批键名，保持同值（契约只加不改）。
            'container_image_digest' => $image['digest'],
            'container_image_status' => $image['status'],
            'recorded_at'            => now()->toIso8601String(),
        ];
    }

    /**
     * 独立构建镜像的摘要 —— 只认环境变量注入，绝不伪造。
     *
     * @return array{ref: ?string, digest: ?string, status: string}
     */
    public static function image(): array
    {
        $ref = self::envValue(self::IMAGE_ENV_REF);
        $rawDigest = self::envValue(self::IMAGE_ENV_DIGEST);

        if ($rawDigest === null) {
            return ['ref' => $ref, 'digest' => null, 'status' => self::STATUS_NOT_RUN];
        }

        if (! preg_match('/^sha256:[0-9a-f]{64}$/', $rawDigest)) {
            // 形如 "latest" / 42 位十六进制 / 带标签的引用 —— 都不是内容摘要。
            // 宁可标 INVALID_DIGEST，也不把不是摘要的串写进"可复现"证据链。
            return ['ref' => $ref, 'digest' => null, 'status' => self::STATUS_INVALID_DIGEST];
        }

        return ['ref' => $ref, 'digest' => $rawDigest, 'status' => self::STATUS_PROBED];
    }

    /** 读取进程级环境变量（空串按未设置处理）。 */
    protected static function envValue(string $name): ?string
    {
        $value = getenv($name);

        if ($value === false) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * 参与构建的构建器类名（排序后，作为"工具链组成"的稳定清单）。
     *
     * @return array<int, string>
     */
    public static function builders(): array
    {
        $classes = [];

        foreach ((array) config('skill_builders.builders', []) as $entry) {
            $class = $entry['class'] ?? null;
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }
}
