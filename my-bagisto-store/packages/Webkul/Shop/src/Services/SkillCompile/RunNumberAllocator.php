<?php

namespace Webkul\Shop\Services\SkillCompile;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Shop\Models\SkillBuildRun;

/**
 * 构建 run_number 的唯一分配器（WBS B2）。
 *
 * 约束：
 *   - 同一 build_key 的序号必须串行分配，禁止 `max()+1` 无锁；
 *   - 分配与 `skill_build_runs` 插入在同一事务内，失败一起回滚，不丢号；
 *   - 只对“确认是本序列表唯一键冲突”的异常做有界重试；
 *     其他 QueryException（死锁之外的权限/磁盘/别表冲突）立即抛出。
 *
 * MySQL 5.7 兼容：使用 `SELECT ... FOR UPDATE` 行锁，不依赖 SKIP LOCKED。
 */
class RunNumberAllocator
{
    public const MAX_ATTEMPTS = 8;

    public const SEQUENCE_TABLE = 'skill_build_sequences';

    public const UNIQUE_INDEX = 'uk_build_sequences_key';

    /**
     * 在同一事务内分配序号并插入构建 Run。
     *
     * @param  array<string, mixed>  $attributes  除 build_key / run_number 外的 SkillBuildRun 字段
     */
    public function createRun(string $buildKey, array $attributes): SkillBuildRun
    {
        $buildKey = trim($buildKey);

        if ($buildKey === '') {
            throw new InvalidArgumentException('build_key 不得为空');
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($buildKey, $attributes) {
                    $runNumber = $this->nextLocked($buildKey);

                    return SkillBuildRun::create(array_merge($attributes, [
                        'build_key'  => $buildKey,
                        'run_number' => $runNumber,
                    ]));
                }, 3);
            } catch (QueryException $exception) {
                // 只有“本序列表”的唯一键冲突才重试；别表冲突、死锁、权限、磁盘错误一律不吞。
                if (! $this->isSequenceDuplicate($exception) || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                // 两个事务同时首插同一 build_key：一个成功，另一个撞唯一键。
                // 退避后重读，走既有行自增分支。
                usleep(random_int(2000, 10000));
            }
        }

        throw new RuntimeException('无法分配构建 run_number：重试次数耗尽');
    }

    /**
     * 必须在事务内调用：对 build_key 行加锁后自增。
     */
    protected function nextLocked(string $buildKey): int
    {
        $row = DB::table(self::SEQUENCE_TABLE)
            ->where('build_key', $buildKey)
            ->lockForUpdate()
            ->first();

        if ($row) {
            $next = (int) $row->last_run_number + 1;

            DB::table(self::SEQUENCE_TABLE)
                ->where('build_key', $buildKey)
                ->update([
                    'last_run_number' => $next,
                    'updated_at'      => now(),
                ]);

            return $next;
        }

        DB::table(self::SEQUENCE_TABLE)->insert([
            'build_key'       => $buildKey,
            'last_run_number' => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return 1;
    }

    /**
     * 精确判断：SQLSTATE 23000 / MySQL 1062，且冲突键名就是本序列表的唯一索引。
     */
    public function isSequenceDuplicate(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();

        if (! in_array($sqlState, ['23000', '1062'], true)
            && ! str_contains($message, 'Integrity constraint violation')
            && ! str_contains($message, 'Duplicate entry')) {
            return false;
        }

        return str_contains($message, self::UNIQUE_INDEX)
            || str_contains($message, self::SEQUENCE_TABLE.'.'.self::UNIQUE_INDEX);
    }
}
