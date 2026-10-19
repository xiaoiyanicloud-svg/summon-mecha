<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;

/**
 * 媒体对象（ADR-0001 §4.9 / G1-D06 / G1-06）。
 *
 * 一条记录只代表**一份不可变字节**：
 *   · `disk` / `storage_key` / `sha256` / `mime` / `byte_size` / `width` / `height`
 *     创建后冻结 —— 换文件必须新建媒体并重新审核，不能原位替换；
 *   · `storage_key` 是私有盘路径，**永不进入公开 DTO**；公开读取必须经过
 *     `MediaAccessService` 的门禁，由 controller 从私有盘流式输出；
 *   · `status` 由 `MediaService` 的审核动作流转：pending / approved /
 *     rejected / quarantined。
 *
 * 模型守卫只兜底物理身份字段；状态机与上传校验由服务层负责。
 */
class EcosystemMedia extends Model
{
    protected $table = 'ecosystem_media';

    protected $fillable = [
        'disk',
        'storage_key',
        'original_name',
        'mime',
        'byte_size',
        'width',
        'height',
        'sha256',
        'status',
        'uploaded_by',
    ];

    protected $casts = [
        'byte_size'   => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
        'uploaded_by' => 'integer',
    ];

    /**
     * 创建后不可原位替换的物理身份列。
     *
     * @var array<int, string>
     */
    public const IMMUTABLE_PHYSICAL_FIELDS = [
        'disk',
        'storage_key',
        'sha256',
        'mime',
        'byte_size',
        'width',
        'height',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            // Eloquent 不会在插入前套用数据库默认值；服务层虽然总是显式写
            // pending，这里仍补一次默认，避免绕过服务的写入留下 NULL。
            if ($media->status === null || $media->status === '') {
                $media->status = 'pending';
            }
        });

        static::saving(function (self $media): void {
            $statuses = array_values((array) config('ecosystem_media.statuses', []));

            if (! in_array((string) $media->status, $statuses, true)) {
                throw new MediaValidationException(sprintf(
                    '媒体 status 必须是 %s 之一，收到「%s」。',
                    implode(' / ', $statuses),
                    (string) $media->status,
                ));
            }
        });

        static::updating(function (self $media): void {
            foreach (self::IMMUTABLE_PHYSICAL_FIELDS as $field) {
                if ($media->isDirty($field)) {
                    throw new MediaValidationException(sprintf(
                        '媒体 %s 的 %s 创建即冻结；换文件必须新建媒体并重新审核，不得原位替换。',
                        (string) $media->getKey(),
                        $field,
                    ));
                }
            }
        });
    }

    /**
     * 引用关系（event_revision / content_revision）。
     */
    public function refs()
    {
        return $this->hasMany(EcosystemMediaRef::class, 'media_id');
    }

    public function isImage(): bool
    {
        return (string) config('ecosystem_media.kinds.'.(string) $this->mime) === 'image';
    }
}
