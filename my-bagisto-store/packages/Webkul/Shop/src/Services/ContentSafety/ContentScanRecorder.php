<?php

namespace Webkul\Shop\Services\ContentSafety;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\ContentSecurityRecord;

/**
 * 内容扫描留痕 —— `content_security_record` 的唯一写入处。
 *
 * 为什么要留痕：内容安全是"机器扫 + 人工判"两段。机器扫完不落库，
 * 事后就无法回答"这条技能当时扫没扫、扫出什么、谁看过、怎么处置的" ——
 * 而合规检查问的正是这些。
 *
 * ⚠ 与 `OperationLogger` 同样的取舍：**写失败不影响主流程**（一次提审不该因为
 *   留痕表写不进去而失败），但**绝不静默** —— 以 error 级别进 Laravel 日志。
 */
class ContentScanRecorder
{
    /**
     * @param  array<string,mixed>  $extra  额外上下文（skill_id / 版本号等），进 raw
     */
    public function record(
        string $targetType,
        int $targetId,
        ScanResult $result,
        string $scanType = 'text',
        array $extra = [],
    ): ?ContentSecurityRecord {
        try {
            return ContentSecurityRecord::create([
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'provider'    => $result->provider,
                'scan_type'   => $scanType,
                'result'      => $this->resultCode($result),
                'labels'      => $result->hits ?: null,
                'raw'         => array_filter([
                    'message'   => $result->message ?: null,
                    'degraded'  => $result->degraded ?: null,
                    'passed'    => $result->passed ?: null,
                ]) + $extra,
                // 机器刚扫完 —— 处置状态默认 pending，由人来推进
                'disposition' => ContentSecurityRecord::DISPOSITION_PENDING,
                'scanned_at'  => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('content_security_record 写入失败：'.$e->getMessage(), [
                'target' => $targetType.':'.$targetId,
            ]);

            return null;
        }
    }

    /**
     * ScanResult → 表里的 result 值域（pass / review / block）。
     *
     * ⚠ **degraded 映射到 `review`，不是 `pass`**：
     *   "没扫成"必须有人看一眼，这正是本项目一贯的「没查 ≠ 合规」。
     *   若落到 pass，一个词表丢失的故障会静默变成"全部合规"。
     */
    protected function resultCode(ScanResult $result): string
    {
        if ($result->degraded) {
            return ContentSecurityRecord::RESULT_REVIEW;
        }

        if ($result->isViolation()) {
            return ContentSecurityRecord::RESULT_BLOCK;
        }

        return ContentSecurityRecord::RESULT_PASS;
    }
}
