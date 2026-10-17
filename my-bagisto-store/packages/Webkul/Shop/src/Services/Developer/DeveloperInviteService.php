<?php

namespace Webkul\Shop\Services\Developer;

use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\DeveloperInvite;

/**
 * 开发者邀请码 —— S-11a（数据层）。
 *
 * 定位（拍板）：邀请码是**落地路由 + 归属 + 来源留痕**，**不是准入闸门**；
 * 准入闸门是「实名 + 协议」。所以本服务只回答两件事：**这个码能不能领**、**怎么原子地领**。
 *
 * 工程约定：
 *   - 全部走 `DB::table('developer_invite')` 的条件 UPDATE（本仓既有惯例）；
 *   - **不**在本服务里开 `DB::transaction` —— 调用方在事务里跑（`claim` 的原子性由
 *     "条件 UPDATE 的 affected === 1" 保证，不依赖悲观锁）。
 */
class DeveloperInviteService
{
    /**
     * 去混淆字符集：剔除易混的 0/O/1/I/L（人工抄码/口述场景）。
     */
    public const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public const CODE_LENGTH = 10;

    /**
     * 唯一索引撞码时的重试次数（撞了就换一个再插）。
     */
    protected const CODE_INSERT_ATTEMPTS = 5;

    /**
     * 生成一个邀请码。
     *
     * @param  int|null  $createdBy  创建人（运营）id，可空
     * @param  string|null  $note  备注
     * @param  int  $maxUses  可用次数（1..255，落 unsignedTinyInteger 列）
     * @param  CarbonInterface|null  $expiresAt  过期时间；null = 永不过期
     */
    public function generate(
        ?int $createdBy,
        ?string $note = null,
        int $maxUses = 1,
        ?CarbonInterface $expiresAt = null
    ): DeveloperInvite {
        // unsignedTinyInteger 上限 255，下界 1（0 次的码没有意义）
        $maxUses = max(1, min(255, $maxUses));

        $id = null;

        for ($attempt = 1; $attempt <= self::CODE_INSERT_ATTEMPTS; $attempt++) {
            try {
                $id = DB::table('developer_invite')->insertGetId([
                    'code'       => $this->randomCode(),
                    'max_uses'   => $maxUses,
                    'times_used' => 0,
                    'expires_at' => $expiresAt,
                    'status'     => DeveloperInvite::STATUS_ACTIVE,
                    'created_by' => $createdBy,
                    'note'       => $note,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                break;
            } catch (QueryException $e) {
                // 唯一索引（uk_dev_invite_code）撞码 —— 换一个再试；用尽仍失败则抛
                if ($attempt >= self::CODE_INSERT_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        return DeveloperInvite::query()->findOrFail($id);
    }

    /**
     * 按码取"可领取"的邀请码：只返回 status=active 且**未过期**且**未用完**的。
     *
     * 用于门户落地页展示"这个码能不能领"。真正领取走 {@see claim()}（原子）。
     */
    public function findRedeemable(string $code): ?DeveloperInvite
    {
        return DeveloperInvite::query()
            ->where('code', $code)
            ->where('status', DeveloperInvite::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereColumn('times_used', '<', 'max_uses')
            ->first();
    }

    /**
     * 给出"为什么这个码领不了"的**人话文案**（分四种，不笼统说"无效"）。
     *
     * 判定优先级：不存在 → 已作废 → 已过期 → 已用完。
     * 若该码其实**可领**，返回空串。（调用方应先 `findRedeemable()` 判定能不能领，
     * 本方法只在领不了时解释原因。）
     */
    public function explainUnredeemable(string $code): string
    {
        $invite = DeveloperInvite::query()->where('code', $code)->first();

        if (! $invite) {
            return '邀请码不存在，请核对后重新输入。';
        }

        if ($invite->status !== DeveloperInvite::STATUS_ACTIVE) {
            return '该邀请码已被作废，请联系邀请你的人。';
        }

        if ($invite->isExpired()) {
            return '该邀请码已于 '.$invite->expires_at->format('Y-m-d H:i').' 过期。';
        }

        if ($invite->isExhausted()) {
            return '该邀请码的使用次数已用完。';
        }

        return '';
    }

    /**
     * **原子**领取：条件 UPDATE（status=active + times_used < max_uses + 未过期），
     * 只有 `affected === 1` 才算领到；否则返回 false。
     *
     * 不加悲观锁、不开本方法自己的事务 —— 并发下由"条件都在 WHERE 里"保证只有一个能成功
     * （另一个的 WHERE 不命中 → affected 0）。
     *
     * ⚠ 领取成功后会往 `developer_invite_usage` 落一行流水；该表
     *   `unique(invite_id, customer_id)` 是**同一人重复领**的数据库级兜底。
     *
     * S-11d：多用途码上**同一 customer 第二次领取**不再靠"撞唯一索引抛异常"——
     * 条件 UPDATE 里加了 `whereNotExists`（该 invite 下该 customer 尚无 usage 行），
     * 第 2 次会因 WHERE 不命中返回 false（**不抛异常**），调用方据此区分
     * "你已领过"（{@see alreadyClaimedBy()}）与"被别人领走"。
     * 仍是**单条条件 UPDATE**，没有"先查再更新"的两步竞态。
     */
    public function claim(
        DeveloperInvite $invite,
        int $customerId,
        ?string $ip,
        ?string $userAgent,
        ?int $developerId = null
    ): bool {
        $affected = DB::table('developer_invite')
            ->where('id', $invite->id)
            ->where('status', DeveloperInvite::STATUS_ACTIVE)
            ->whereColumn('times_used', '<', 'max_uses')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereNotExists(function ($q) use ($customerId) {
                $q->select(DB::raw(1))
                    ->from('developer_invite_usage')
                    ->whereColumn('developer_invite_usage.invite_id', 'developer_invite.id')
                    ->where('developer_invite_usage.customer_id', $customerId);
            })
            ->update([
                'times_used' => DB::raw('times_used + 1'),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return false;
        }

        DB::table('developer_invite_usage')->insert([
            'invite_id'    => $invite->id,
            'customer_id'  => $customerId,
            'developer_id' => $developerId,
            'ip'           => $ip,
            'user_agent'   => $userAgent,
            'used_at'      => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return true;
    }

    /**
     * 该 customer 是否已经领过这张码（只读；用于区分"你已领过"与"被别人领走"）。
     */
    public function alreadyClaimedBy(DeveloperInvite $invite, int $customerId): bool
    {
        return DB::table('developer_invite_usage')
            ->where('invite_id', $invite->id)
            ->where('customer_id', $customerId)
            ->exists();
    }

    /**
     * 10 位、取自去混淆字符集的随机码。
     */
    protected function randomCode(): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
