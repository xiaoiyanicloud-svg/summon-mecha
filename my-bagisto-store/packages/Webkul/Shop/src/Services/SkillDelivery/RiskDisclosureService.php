<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\RiskAcknowledgment;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillPackage\SkillManifest;

/**
 * 风险告知（B-5 · 计划 §4.14「用户分级告知留痕」/ D6 三层责任模型）。
 *
 * 职责两半，缺一不可：
 *   ① **告知**：按 `skill_versions.risk_level`（来自 `manifest.safety.risk_level`）
 *      给出分档文案与勾选项 —— low/medium 只提示，high 必须**逐条勾选**；
 *   ② **留痕**：把"谁、对哪个版本、勾了哪些、什么时候、什么 IP/UA、当时那份文案的指纹"
 *      落进 `risk_acknowledgment`。
 *
 * ⚠ 三档文案的判据是 `manifest.safety.risk_level`（low/medium/high），
 *   **不是**机型兼容性分级 L1/L2/L3 —— 两者同字母但完全无关（见 ai-project-brief §2.3）。
 *
 * ⚠ 未声明的等级**不臆造**：没读到 `risk_level` 就如实说"未分级"，而不是默认成 low
 *   让人以为"平台说这技能安全"。
 */
class RiskDisclosureService
{
    /**
     * 各等级的分档文案与勾选项。
     *
     * 取值即线上契约（前端按 key 提交勾选结果），改名可以、改 key 须同步前端。
     */
    protected const COPY = [
        'low' => [
            'label'            => '低风险',
            'summary'          => '该技能为常规动作，但仍需在设备可安全活动的区域内运行。',
            'items'            => [],
            'require_checklist' => false,
        ],
        'medium' => [
            'label'            => '中风险',
            'summary'          => '该技能包含较大幅度的动作，请在设备周围留出足够空间后运行。',
            'items'            => [
                ['key' => 'confirm_space', 'text' => '我已确认设备周围有足够的活动空间'],
                ['key' => 'confirm_estop', 'text' => '我已了解紧急停止的操作方式'],
            ],
            'require_checklist' => false,
        ],
        'high' => [
            'label'            => '高风险',
            'summary'          => '该技能为高风险动作，必须在受控环境下由人员看护运行。',
            'items'            => [
                ['key' => 'confirm_open_area', 'text' => '我已确认将在空旷、无人的环境中运行'],
                ['key' => 'confirm_estop', 'text' => '我已了解并能在需要时立即使用紧急停止'],
                ['key' => 'confirm_supervision', 'text' => '我已确认运行全过程有人在场看护'],
            ],
            'require_checklist' => true,
        ],
        // 未声明（manifest 缺 risk_level / 老版本）—— 如实标注，不假装是 low
        'unknown' => [
            'label'            => '未分级',
            'summary'          => '该技能未声明风险等级，请谨慎使用，必要时先联系客服确认。',
            'items'            => [],
            'require_checklist' => false,
        ],
    ];

    /**
     * 技能的风险告知档案（前端弹窗的数据源）。
     *
     * @return array|null  null = 该商品没有可展示的已发布版本
     */
    public function profile(int $productId): ?array
    {
        $version = SkillVersion::query()
            ->where('product_id', $productId)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        if (! $version) {
            return null;
        }

        $level = $this->normalizeLevel($version->risk_level);
        $copy = self::COPY[$level];

        // D-8 改判版 · 权限声明可见化：把开发者声明的权限**并入既有的 `summary` 文案**。
        //   为什么并进 summary 而不是新增字段：前端弹窗（`pages/home/jineng.vue`）页内块与
        //   modal **已经在渲染 `summary`**（.risk-summary / .risk-modal-summary），并进去
        //   既有的前端**不用改就能显示**；新增字段反而要同步改前端。
        //   ⚠ 为什么不用 `items`：items 是高风险**逐条勾选**项（其 `key` 会被前端当 checked
        //     回传落库），权限是**陈述**不是"要勾的承诺"，塞进去会污染勾选语义。
        //   文案用人话标签（取自 SkillManifest::PERMISSION_LABELS，唯一中文来源），
        //   未声明权限时**不追加**（不凭空造一句"未声明权限"占位）。
        $permissionNote = $this->permissionNote($version->permissions);
        if ($permissionNote !== '') {
            $copy['summary'] = rtrim($copy['summary']).$permissionNote;
        }

        return [
            'product_id'        => $productId,
            'skill_version_id'  => $version->id,
            'risk_level'        => $level === 'unknown' ? null : $level,
            'label'             => $copy['label'],
            'summary'           => $copy['summary'],
            'items'             => $copy['items'],
            'require_checklist' => $copy['require_checklist'],
            // 文案指纹：证明"当时告知的是这一份"。前端会回传，服务端据此落库。
            // ⚠ 指纹取的是**含权限声明之后**的 summary —— 告知的内容变过，指纹就该跟着变。
            'disclosure_hash'   => $this->hashFor($copy),
        ];
    }

    /**
     * 把开发者声明的权限渲染成一句人话，追加进风险告知文案。
     *
     * 例：`['motion_control','camera']` → `该技能运行时会涉及：运动控制、摄像头。`
     *
     * ⚠ 用**人话标签**而不是原始枚举（用户该看到"摄像头"，不是 `camera`）；
     *   未收录的码（理论上过不了白名单）回落成原始码，宁可露出也不静默吞掉。
     *   无声明（null / 空数组 / 全是空串）→ 返回空串，调用方据此**不追加**。
     */
    protected function permissionNote(mixed $permissions): string
    {
        if (! is_array($permissions) || $permissions === []) {
            return '';
        }

        $labels = [];
        foreach ($permissions as $p) {
            if (! is_string($p) || $p === '') {
                continue;
            }
            $labels[] = SkillManifest::PERMISSION_LABELS[$p] ?? $p;
        }

        $labels = array_values(array_unique($labels));

        if ($labels === []) {
            return '';
        }

        return '该技能运行时会涉及：'.implode('、', $labels).'。';
    }

    /**
     * 落一条风险告知留痕。
     *
     * @return array{ok: bool, code: ?string, message: string, record: ?RiskAcknowledgment}
     */
    public function acknowledge(
        int $customerId,
        int $productId,
        ?int $skillVersionId,
        ?int $entitlementId,
        ?string $channel,
        array $checked,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $profile = $this->profile($productId);

        if (! $profile) {
            return $this->fail('RISK_PROFILE_NOT_FOUND', '该技能暂无可展示的风险告知信息。', 404);
        }

        // high 风险必须逐条勾选 —— 这是 D6「用户分级告知留痕」的硬要求，不是可选项
        if ($profile['require_checklist']) {
            $required = array_column($profile['items'], 'key');
            $missing = array_values(array_diff($required, $checked));

            if ($missing) {
                return $this->fail(
                    'RISK_ACK_REQUIRED',
                    '高风险技能需逐条确认告知项后才能继续。',
                    422,
                );
            }
        }

        $record = RiskAcknowledgment::create([
            'customer_id'      => $customerId,
            // 授权可能还没有（下单前告知），故可空
            'entitlement_id'   => $entitlementId,
            'skill_version_id' => $skillVersionId ?: $profile['skill_version_id'],
            'channel'          => $channel,
            'ack_payload'      => [
                'checked'          => array_values($checked),
                'risk_level'       => $profile['risk_level'],
                'disclosure_hash'  => $profile['disclosure_hash'],
                'ip'               => $ip,
                'user_agent'       => $userAgent,
                'acknowledged_at'  => now()->toDateTimeString(),
            ],
            'ip'               => $ip,
        ]);

        return ['ok' => true, 'code' => null, 'message' => '风险告知已确认。', 'record' => $record];
    }

    /**
     * 归一化等级：只认 low/medium/high，其余（含 null）一律 'unknown'。
     */
    protected function normalizeLevel(?string $level): string
    {
        return in_array($level, ['low', 'medium', 'high'], true) ? $level : 'unknown';
    }

    /**
     * 告知文案的 sha256 —— 与开发者协议 `agreement_text_hash` 同一个思路：
     * 留痕要留的是"当时那份话"，不是"我们现在还在说这句话"。
     */
    protected function hashFor(array $copy): string
    {
        $text = $copy['label'].'|'.$copy['summary'].'|'.implode('|', array_column($copy['items'], 'text'));

        return hash('sha256', $text);
    }

    /**
     * @return array{ok: false, code: string, message: string, status: int, record: null}
     */
    protected function fail(string $code, string $message, int $status = 200): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'status' => $status, 'record' => null];
    }
}
