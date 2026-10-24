<?php

namespace Webkul\Shop\Services\SkillSupply;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;

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
     * 已发布的版本不允许再上传/覆盖交付物（任务卡 D-7 · ⑨）。
     *
     * 与 `SkillPublisher::ERR_*` 分开成一枚自己的码：那些是**发布**时的理由码，
     * 这枚是**上传**时的。调用方（API 两条通道）按它映射到 ApiErrorCode。
     */
    public const ERR_VERSION_PUBLISHED_IMMUTABLE = 'VERSION_PUBLISHED_IMMUTABLE';

    public function __construct(
        /**
         * 状态机唯一入口 —— 上传成功后可能需要把"在审"的版本退回草稿（见 store()）。
         * 这里注入而不是在控制器里补一句：上传有三个入口（internal.key / 开发者门户 / 运营后台），
         * 写在控制器里必然漏掉某一个，而漏掉的那个就会留下一个"已通过审核"的假状态。
         */
        protected SkillVersionState $states,
    ) {}

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

        // 已发布的版本**不许换包**（任务卡 D-7 · ⑨）—— 必须拦在**落盘之前**：
        //   发布之后这份包可能已经被买家下载/安装了，覆盖掉它等于让"线上那一份"在
        //   没有任何记录的情况下变成另一份字节流（`file_hash` 只在行上，没有第二处能发现）。
        //   要改内容就发新版本：版本号是"哪一份"的唯一标识。
        if ($version->status === SkillVersion::STATUS_PUBLISHED) {
            return AssetUploadResult::fail(
                '该版本已发布，交付物不可再改动（买家可能已经下载或安装了这一份）。'
                .'请新建一个版本上传新内容，走审核后发布。',
                409,
                [],
                self::ERR_VERSION_PUBLISHED_IMMUTABLE
            );
        }

        if (in_array($carrierType, SkillAsset::FILE_BACKED_CARRIERS, true) && ! $file) {
            return AssetUploadResult::fail("{$carrierType} 类型载体必须上传文件。", 422);
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

        // 覆盖前先记下旧哈希 —— 判断"这次到底换成没有"要用它（见下方在审退回）
        $previousHash = $existing?->file_hash;

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

        $reverted = $this->syncLifecycleAfterUpload($version, $asset, $previousHash);

        // 状态被退回过（文件类交付物在审期间被换掉）时**如实说出来** ——
        // 一个静默的状态回退会让开发者以为"提审还在"，直到他发现队列里没有它。
        return AssetUploadResult::ok($asset, $reverted
            ? '交付物已上传。该版本已退回草稿（交付物与审核时的那一份不同），请重新提交审核。'
            : '交付物已上传。');
    }

    /**
     * 上传成功后，版本生命周期要不要跟着动（任务卡 D-7 · ⑨ · 状态口径）。
     *
     * 病：版本处于「在审」（`in_review`）时重传交付物 —— 那条"**已通过**"的审核记录
     *   对应的是**旧字节**，事实上已经作废。若状态不动，审核台队列（口径 = `in_review`
     *   | `rejected`）里仍会把它列进"已通过（待发布）"，运营看到的是一个**假状态**：
     *   点发布会被包完整性闸门挡住，而队列上却显示一切正常。
     *
     * 处置：**退回 `draft`**，让它离开队列，由开发者重新提审。
     *   （`in_review → draft` 是 `SkillVersionState::TRANSITIONS` 里**已有**的边 ——
     *     「撤回提审」走的就是它。本卡只新增一个触发点，**不新增任何迁移边**。
     *     换包在语义上就是一次"事实撤回"：旧提审对应的那份包已经不存在了。）
     *
     * 触发条件刻意收紧，避免无谓地把提审打回：
     *   · 只对**文件类**载体 —— 非文件类（cloud_config 等）不参与包指纹，改 payload
     *     不影响审核结论，不该把版本踢出队列；
     *   · 只有**字节确实变了**（新增交付物，或 `file_hash` 与原来不同）才算 ——
     *     网络重试导致的同字节重传不该作废一次正常的审核。
     *
     * ⚠ `rejected → draft` 这条边**不加**，也不需要：被驳回的版本本就在"待开发者整改"态，
     *   重传后它仍是 `rejected`（真状态，不是假状态），开发者的下一步（重新提审）本来就允许。
     *   加这条边只会凭空多出一条"驳回后就地变草稿"的路径，把"提审过且被驳回"这个事实抹掉。
     *
     * @return bool 是否真的把版本退回了草稿（调用方据此在提示语里如实说明）
     */
    protected function syncLifecycleAfterUpload(SkillVersion $version, SkillAsset $asset, ?string $previousHash): bool
    {
        if ($version->status !== SkillVersion::STATUS_IN_REVIEW) {
            return false;
        }

        if (! $asset->isFile()) {
            return false;
        }

        // 新上传的文件一定带 hash（storeFile 里已复算并复核）；旧行没有 hash 时无法证明
        // "字节没变"，按变了处理（fail-safe）。
        $changed = $previousHash === null || $previousHash !== $asset->file_hash;

        if (! $changed) {
            return false;
        }

        $result = $this->states->transition($version, SkillVersion::STATUS_DRAFT);

        if (! $result['ok']) {
            // 不静默吞：状态没退回去 → 队列里会留一个假状态，必须留下痕迹（但不炸掉上传本身，
            // 文件已经落盘、记录已经写好了，把它报成"上传失败"会误导调用方重传）。
            report(new \RuntimeException(
                "在审版本重传交付物后未能在状态机上退回草稿：#{$version->id}（{$result['code']} {$result['message']}）"
            ));

            return false;
        }

        return true;
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
