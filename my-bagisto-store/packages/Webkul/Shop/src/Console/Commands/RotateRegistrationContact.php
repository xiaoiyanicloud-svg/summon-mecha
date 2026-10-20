<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;

/**
 * G2-11 · 联系人密文密钥轮换。
 *
 * 前置：新 APP_KEY 已生效；旧 APP_KEY 配置在
 * `ECOSYSTEM_REGISTRATION_PREVIOUS_APP_KEYS`（逗号分隔）。
 *
 * 行为：
 *   · 当前密钥能解开的行跳过；
 *   · 旧密钥能解开的行用当前密钥重新加密，并按当前 pii_key 重算 phone_hash；
 *   · 默认 dry-run，只统计；`--apply` 才写库；
 *   · 输出只含 ID/计数，不含姓名/手机号。
 *
 * 轮换完成后：验证公开报名/登录/导出仍可解密，再移除旧 key。
 */
class RotateRegistrationContact extends Command
{
    protected $signature = 'ecosystem:rotate-registration-contact
        {--apply : 实际写回重新加密的字段（默认 dry-run）}
        {--chunk=200 : 每批处理条数}';

    protected $description = '用旧 APP_KEY 解密、当前 APP_KEY 重新加密报名联系人密文';

    public function handle(RegistrationContactVault $vault): int
    {
        $previousKeys = (array) config('ecosystem_registration.previous_app_keys', []);

        if ($previousKeys === []) {
            $this->error('未配置历史 APP_KEY（ECOSYSTEM_REGISTRATION_PREVIOUS_APP_KEYS），不能轮换。');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $chunk = max(1, min(1000, (int) $this->option('chunk')));
        $counts = ['checked' => 0, 'current' => 0, 'rotated' => 0, 'failed' => 0];
        $failedIds = [];

        EventRegistrationAttempt::query()->chunkById($chunk, function ($attempts) use ($apply, $vault, &$counts, &$failedIds): void {
            foreach ($attempts as $attempt) {
                $counts['checked']++;

                try {
                    Crypt::decryptString((string) $attempt->contact_name_enc);
                    $counts['current']++;
                    continue;
                } catch (Throwable) {
                    // 当前密钥不可读，尝试历史 key ring。
                }

                try {
                    $name = $vault->decryptName((string) $attempt->contact_name_enc);
                    $phone = $vault->decryptPhone((string) $attempt->contact_phone_enc);
                    $note = $vault->decryptNote($attempt->note_enc);
                } catch (Throwable) {
                    $counts['failed']++;
                    $failedIds[] = (int) $attempt->getKey();
                    continue;
                }

                if (! $apply) {
                    $counts['rotated']++;
                    continue;
                }

                DB::transaction(function () use ($attempt, $name, $phone, $note, $vault): void {
                    DB::table('event_registration_attempts')
                        ->where('id', $attempt->getKey())
                        ->update([
                            'contact_name_enc'    => Crypt::encryptString($name),
                            'contact_phone_enc'   => Crypt::encryptString($phone),
                            'contact_phone_hash'  => $vault->hashPhone($phone),
                            'contact_phone_last4' => $vault->last4($phone),
                            'note_enc'            => $note === null ? null : Crypt::encryptString($note),
                            'updated_at'          => now(),
                        ]);
                });

                $counts['rotated']++;
            }
        });

        $this->info(sprintf(
            '%s检查 %d；当前密钥可读 %d；%s %d；失败 %d。',
            $apply ? '' : '[DRY-RUN] ',
            $counts['checked'],
            $counts['current'],
            $apply ? '已轮换' : '可轮换',
            $counts['rotated'],
            $counts['failed'],
        ));

        if ($failedIds !== []) {
            $this->warn('无法用任何密钥解密的 attempt IDs：'.implode(', ', array_slice($failedIds, 0, 50)));
        }

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
