<?php

namespace Webkul\Shop\Services\ContentSafety;

/**
 * 内容扫描器契约 —— v3.4 步骤 6。
 *
 * 加一种扫描方式（微信 msgSecCheck / 自建模型 / 第三方合规服务）
 * = 实现本接口 + 在 config/content_safety.php 注册一行，业务代码零改动。
 */
interface ContentScanner
{
    /**
     * 驱动标识（写进审核记录，便于追"这次是谁扫的"）。
     */
    public function key(): string;

    /**
     * 扫描一段文本。
     *
     * ⚠ 实现者注意：**扫描不了的时候要返回 `ScanResult::degraded()`，不要返回 pass()**。
     *   把"没查"报成"通过"会让上层误以为内容已合规。
     */
    public function scan(string $text): ScanResult;
}
