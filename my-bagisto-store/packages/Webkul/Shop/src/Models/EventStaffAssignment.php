<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 活动人员授权（G2-14）。
 *
 * 全局 ACL + 本表有效 capability 同时满足才放行。
 * 只有 `permission_type=all` 的超管可以写本表；写入校验在服务层。
 */
class EventStaffAssignment extends Model
{
    protected $table = 'event_staff_assignments';

    public const CAPABILITIES = ['review', 'cancel', 'pii', 'export', 'checkin', 'settings'];

    protected $fillable = [
        'event_id',
        'admin_id',
        'capabilities',
        'valid_from',
        'valid_until',
        'granted_by',
        'note',
    ];

    protected $casts = [
        'event_id'     => 'integer',
        'admin_id'     => 'integer',
        'capabilities' => 'array',
        'valid_from'   => UtcDateTime::class,
        'valid_until'  => UtcDateTime::class,
        'granted_by'   => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, (array) $this->capabilities, true);
    }

    public function isActiveAt(mixed $now = null): bool
    {
        $now = $now ? \Illuminate\Support\Carbon::parse($now, 'UTC') : now('UTC');

        if ($this->valid_from !== null && $this->valid_from->greaterThan($now)) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->greaterThanOrEqualTo($now);
    }
}
