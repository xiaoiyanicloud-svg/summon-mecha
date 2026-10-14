<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * 技能交付物 —— 一个版本可以带 0..N 个,这是「技能载体有多种」的落点。
 *
 * carrier_type:
 *   file           —— 文件包(安装包/模型/脚本),落 private 盘
 *   cloud_config   —— 云端下发的一组配置/参数(存 payload)
 *   remote_service —— 远程协助类,无实体文件,仅有说明
 *   api_bundle     —— 对接用的接口定义(存 payload)
 *
 * ⚠ 安全约束(务必遵守,这是本表独立于 products 的核心原因):
 *   file_path 必须落在 private 盘,且**绝不能被任何面向买家的接口透出**。
 *   曾经评估过用 Bagisto 的 file 类型自定义属性存技能包,被否决 ——
 *   那条路落的是 public 盘(ProductAttributeValueRepository 没传磁盘参数),
 *   而且 ProductResource::customAttributes() 会把路径明文吐给 GET /api/products/{id},
 *   前端 detailSpecs 还会把它渲染进商品详情的「产品参数」。等于付费包路径印在详情页上。
 */
class SkillAsset extends Model
{
    /**
     * 载体类型白名单。
     */
    public const CARRIER_FILE = 'file';

    public const CARRIER_CLOUD_CONFIG = 'cloud_config';

    public const CARRIER_REMOTE_SERVICE = 'remote_service';

    public const CARRIER_API_BUNDLE = 'api_bundle';

    /**
     * v3.4 新增载体(计划 §3.1/§4.20):
     *   motion_data  —— 通用动作数据(可跨本体重定向)
     *   app_package  —— 容器化应用包(宇树 Docker 载体)
     *   vendor_package —— 厂商专有资源包(智元 mcap / 众擎 Runner)
     */
    public const CARRIER_MOTION_DATA = 'motion_data';

    public const CARRIER_APP_PACKAGE = 'app_package';

    public const CARRIER_VENDOR_PACKAGE = 'vendor_package';

    public const CARRIER_TYPES = [
        self::CARRIER_FILE,
        self::CARRIER_CLOUD_CONFIG,
        self::CARRIER_REMOTE_SERVICE,
        self::CARRIER_API_BUNDLE,
        self::CARRIER_MOTION_DATA,
        self::CARRIER_APP_PACKAGE,
        self::CARRIER_VENDOR_PACKAGE,
    ];

    /**
     * 私密盘名 —— 技能包不能走 public。
     *
     * ⚠ 这是**默认值**，不是唯一答案：实际用哪个盘由 `config('skill_delivery.asset_disk')` 决定
     * （v3.4 步骤 3：切对象存储时只改配置 + 跑一次 `skill:move-assets`，业务代码零改动）。
     * **代码里请一律用 `SkillAsset::disk()`，不要直接引这个常量** ——
     * 直接引常量会让"切盘"变成一次全局替换。
     */
    public const DISK = 'private';

    /**
     * 当前技能包该落在哪个盘。
     *
     * ⚠ 不能写成 `(string) config('skill_delivery.asset_disk', self::DISK)` ——
     *   config 键**存在但为 null/空**时（例如 `.env` 里写了 `SKILL_ASSET_DISK=`），
     *   默认值不生效，(string) null 会得到**空盘名**，运行时报出难懂的错。
     *   这里显式兜底。
     */
    public static function disk(): string
    {
        $disk = config('skill_delivery.asset_disk');

        return is_string($disk) && $disk !== '' ? $disk : self::DISK;
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'skill_assets';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'skill_version_id',
        'seller_id',
        'carrier_type',
        'file_path',
        'file_name',
        'file_size',
        'file_hash',
        'payload',
        'entrypoint',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'payload'    => 'array',
        'file_size'  => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * 所属版本。
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    /**
     * 是否是文件类载体。
     */
    public function isFile(): bool
    {
        return $this->carrier_type === self::CARRIER_FILE;
    }

    /**
     * 取私密盘上的真实路径 —— 只给服务端下载逻辑用,不要放进 API 响应。
     */
    public function diskPath(): ?string
    {
        return $this->file_path;
    }

    /**
     * 文件是否真实存在于私密盘。
     */
    public function fileExists(): bool
    {
        return $this->isFile()
            && $this->file_path
            && Storage::disk(static::disk())->exists($this->file_path);
    }
}
