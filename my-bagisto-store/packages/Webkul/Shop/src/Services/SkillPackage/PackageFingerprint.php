<?php

namespace Webkul\Shop\Services\SkillPackage;

use Webkul\Shop\Models\SkillAsset;

/**
 * 包指纹 —— 「审的就是发的」的**唯一判据**（任务卡 D-7 · 平台化 ⑨）。
 *
 * ── 为什么必须有它 ──
 *   交付物按 `(skill_version_id, carrier_type, sort_order)` **幂等 upsert**
 *   （见 `AssetUploadService::store`）：重复上传会**覆盖记录并删掉旧文件**。
 *   于是"提审之后、发布之前再传一次包"会把落盘的东西换掉，而那条"审核通过"的
 *   记录看起来依然有效 —— **审的那个包**与**发出去的那个包**可以是两个不同的字节流，
 *   且此前**没有任何记录能发现**。对一个技能交易平台来说，这是信任底线。
 *
 *   上传时确实复算了 sha256 并写进 `skill_assets.file_hash`，但那个哈希只用于
 *   "上传时自证"（声明值 vs 实际值），对"审核之后有没有被换"毫无约束。
 *
 * ── 判据是什么 ──
 *   对某版本的**文件类**交付物，按 `(carrier_type, sort_order)` 升序排列，逐项取
 *   `carrier_type|sort_order|file_hash`，再用 `\n` 拼成一个串，最后取 sha256。
 *   排序是为了让"同一组交付物、入库先后不同"得到同一个指纹（入库顺序不是语义）。
 *   项与项之间用 `\n` 而不是直接相接：`file_hash` 可能为 NULL（历史行），
 *   此时逐项长度不定，直接相接会出现"不同集合拼出同一串"的歧义。
 *
 * ── 为什么**绝对不参与**非文件类交付物 ──
 *   `cloud_config` / `remote_service` / `api_bundle` 这类载体**按设计不落盘**，
 *   它们只有 `payload`（见 `AssetUploadService` 的非文件分支）——
 *   **没有字节，就没有"那份包"可言**。把它们算进指纹会带来两个错误后果：
 *     ① 改一条云端配置（运行参数、endpoint）会被判成"包变了"，把发布无谓地拦死；
 *     ② 反过来，指纹里掺进没有字节的东西，会让"指纹相同"不再蕴含"字节相同"——
 *        判据本身就被稀释了。
 *   所以本类一律用 `SkillAsset::isFile()` 过滤（与 `SkillAsset::fileExists()`、
 *   C 端鉴权下载用的是同一对判据，不另立一套）。
 *
 * ⚠ **只此一处**：审核侧写指纹（`AuditService`）与发布侧比指纹（`SkillPublisher`）
 *   必须调同一个方法。任何一处另写一遍，就等于把"审的就是发的"这句话交给两份实现去兑现，
 *   而它们迟早会漂移 —— 漂移的那次就是一次换包事故。
 */
final class PackageFingerprint
{
    /**
     * 取某版本当前的文件类交付物指纹。
     *
     * 直接查库、不走内存里的关联：判据读的必须是**库里此刻的事实**，
     * 而不是调用方手上那份可能已经过期的 `assets` 集合。
     *
     * @return string|null 没有文件类交付物时返回 **null**（不是一个空串冒充的"有效指纹"）。
     *                     调用方据此决定语义：无文件类交付物 = 没有"字节"可审可发。
     */
    public function forVersion(int $versionId): ?string
    {
        $entries = SkillAsset::query()
            ->where('skill_version_id', $versionId)
            // 排序：carrier_type 升序 → sort_order 升序（题设判据，勿改）
            ->orderBy('carrier_type')
            ->orderBy('sort_order')
            ->get()
            // 非文件类载体没有字节，不参与（理由见类注释）
            ->filter(fn (SkillAsset $asset) => $asset->isFile())
            ->map(fn (SkillAsset $asset) => $asset->carrier_type.'|'.$asset->sort_order.'|'.(string) $asset->file_hash)
            ->values();

        // ⚠ 返回 null 而不是 hash('sha256', '')：
        //   空串的 sha256 是一个**看起来合法的指纹**，会让"这个版本没有包"伪装成
        //   "这个版本的包指纹是 X"，从而在比对时产生一个虚假的"一致"或"不一致"。
        if ($entries->isEmpty()) {
            return null;
        }

        return hash('sha256', $entries->implode("\n"));
    }
}
