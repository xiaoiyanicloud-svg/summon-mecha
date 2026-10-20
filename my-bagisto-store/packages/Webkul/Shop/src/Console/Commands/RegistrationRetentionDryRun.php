<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;

/**
 * G2-11 · 报名资料留存 dry-run。
 *
 * B-05（留存期限、注销、删除与审计保留）未签署前：
 *   · 不删除、不匿名化、不批量转移任何历史记录；
 *   · 本命令只输出按状态/年份分组的数量，以及密钥可读性抽样；
 *   · 输出不含姓名/手机号/密文。
 *
 * `cleanup_enabled` 在本版本固定 false；即使配置误开，也没有 apply 路径。
 */
class RegistrationRetentionDryRun extends Command
{
    protected $signature = 'ecosystem:registration-retention
        {--check-key : 抽样解密一条最近报名，验证当前密钥可读（只输出成功/失败）}';

    protected $description = '报名资料留存 dry-run（只统计，不删除）';

    public function handle(RegistrationContactVault $vault): int
    {
        if ((bool) config('ecosystem_registration.retention.cleanup_enabled', false)) {
            $this->warn('cleanup_enabled 当前不受支持：本命令仍只做 dry-run，不会删除任何记录。');
        }

        $days = max(1, (int) config('ecosystem_registration.retention.attempt_retention_days', 365));
        $cutoff = now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        $this->line(sprintf('留存窗口：%d 天；早于 %s（UTC）的轮次仅统计，不处理。', $days, $cutoff));

        $rows = DB::table('event_registration_attempts')
            ->select('status', DB::raw('COUNT(*) as total'))
            ->where('submitted_at', '<', $cutoff)
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $total = 0;

        foreach ($rows as $row) {
            $this->line(sprintf('  %s: %d', $row->status, (int) $row->total));
            $total += (int) $row->total;
        }

        $legacy = (int) EventRegistration::query()
            ->where('identity_kind', EventRegistration::IDENTITY_LEGACY)
            ->count();

        $this->line(sprintf('历史 legacy 报名：%d 条（不自动认领、不自动清理）', $legacy));
        $this->line(sprintf('超过留存窗口的有效轮次合计：%d 条；未执行任何删除。', $total));

        if ((bool) $this->option('check-key')) {
            $sample = EventRegistrationAttempt::query()->orderByDesc('id')->first();

            if ($sample === null) {
                $this->line('密钥抽样：无报名轮次可抽样。');

                return self::SUCCESS;
            }

            try {
                $vault->decryptName((string) $sample->contact_name_enc);
                $vault->decryptPhone((string) $sample->contact_phone_enc);
                $this->info('密钥抽样：当前密钥可解密最近一条轮次（未输出明文）。');
            } catch (\Throwable $exception) {
                $this->error('密钥抽样：解密失败（'.$exception::class.'）；请检查 APP_KEY / 轮换记录。');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
