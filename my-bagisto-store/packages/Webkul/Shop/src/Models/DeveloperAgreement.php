<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 开发者协议签署留痕 —— v3.4 步骤 9。
 *
 * `sign_payload` 存的是"当时的证据"：勾选、文档版本、**正文指纹**、IP、UA、时间。
 * 其中 `agreement_text_hash` 是关键 —— 将来改了协议正文，能证明开发者当年签的是哪一版。
 */
class DeveloperAgreement extends Model
{
    public const DOC_DEV_AGREEMENT = 'dev_agreement';

    public const DOC_SAFETY_COMMITMENT = 'safety_commitment';

    protected $table = 'developer_agreement';

    protected $fillable = [
        'developer_id',
        'doc_type',
        'doc_version',
        'signed_at',
        'sign_payload',
    ];

    protected $casts = [
        'developer_id' => 'integer',
        'signed_at'    => 'datetime',
        'sign_payload' => 'array',
    ];

    public function developer(): BelongsTo
    {
        return $this->belongsTo(DeveloperProfile::class, 'developer_id');
    }
}
