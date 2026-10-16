<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Customer\Models\Customer;

/**
 * 邀请码领取流水 —— S-11a（数据层）。
 *
 * append-only：一行 = 一次领取（谁、何时、从哪个 IP/UA 领的）。
 * `unique(invite_id, customer_id)` 保证**同一码同一人只落一行**（多用途码是发给多个人，
 * 不是给同一个人领多次）。
 */
class DeveloperInviteUsage extends Model
{
    protected $table = 'developer_invite_usage';

    protected $fillable = [
        'invite_id',
        'customer_id',
        'developer_id',
        'ip',
        'user_agent',
        'used_at',
    ];

    protected $casts = [
        'invite_id'    => 'integer',
        'customer_id'  => 'integer',
        'developer_id' => 'integer',
        'used_at'      => 'datetime',
    ];

    public function invite(): BelongsTo
    {
        return $this->belongsTo(DeveloperInvite::class, 'invite_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
