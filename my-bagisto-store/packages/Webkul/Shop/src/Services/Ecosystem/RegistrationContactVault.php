<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\Crypt;

/**
 * G2-11 · 联系人加密/遮罩/查重键。
 *
 * 分区：
 *   · 姓名/手机号/备注用 APP_KEY 的 Crypt 加密（AES-256），密文用 TEXT 存，不截断；
 *   · 查重使用独立 HMAC-SHA256（`ecosystem_registration.pii_key`），
 *     不存可穷举的裸手机号散列；
 *   · 展示默认遮罩，只有 `maskName` / `maskPhone` 的输出可以进列表。
 *
 * 解密只允许在受权动作里调用；调用方必须写审计，不得把明文写进日志/告警/outbox。
 */
class RegistrationContactVault
{
    /**
     * @return array{contact_name_enc: string, contact_phone_enc: string, contact_phone_hash: string, contact_phone_last4: string, contact_city: string, note_enc: ?string}
     */
    public function buildContact(string $name, string $phone, ?string $city = null, ?string $note = null): array
    {
        $name = trim($name);
        $phone = trim($phone);

        if ($name === '') {
            throw RegistrationException::invalid('联系人姓名不能为空。');
        }

        if (mb_strlen($name) > (int) config('ecosystem_registration.form.max_lengths.name', 50)) {
            throw RegistrationException::invalid('联系人姓名过长。');
        }

        $normalized = $this->normalizePhone($phone);

        if ($normalized === null) {
            throw RegistrationException::invalid('手机号格式不合法。');
        }

        if ($city !== null && mb_strlen(trim($city)) > 100) {
            throw RegistrationException::invalid('城市过长。');
        }

        if ($note !== null && mb_strlen(trim($note)) > (int) config('ecosystem_registration.form.max_lengths.note', 500)) {
            throw RegistrationException::invalid('备注过长。');
        }

        return [
            'contact_name_enc'   => Crypt::encryptString($name),
            'contact_phone_enc'  => Crypt::encryptString($normalized),
            'contact_phone_hash' => $this->hashPhone($normalized),
            'contact_phone_last4'=> $this->last4($normalized),
            'contact_city'       => $city !== null ? trim($city) : '',
            'note_enc'           => $note !== null && trim($note) !== ''
                ? Crypt::encryptString(trim($note))
                : null,
        ];
    }

    public function decryptName(string $cipher): string
    {
        return $this->decryptWithKeyRing($cipher);
    }

    public function decryptPhone(string $cipher): string
    {
        return $this->decryptWithKeyRing($cipher);
    }

    public function decryptNote(?string $cipher): ?string
    {
        return $cipher === null ? null : $this->decryptWithKeyRing($cipher);
    }

    /**
     * 当前 APP_KEY 优先；失败时尝试历史 APP_KEY（轮换期只读兼容）。
     *
     * @throws RegistrationException 所有密钥都无法解密
     */
    private function decryptWithKeyRing(string $cipher): string
    {
        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable) {
            // 继续尝试历史密钥。
        }

        foreach ($this->previousKeys() as $key) {
            try {
                $encrypter = new \Illuminate\Encryption\Encrypter(
                    $this->parseKey($key),
                    (string) config('app.cipher', 'AES-256-CBC'),
                );

                return $encrypter->decryptString($cipher);
            } catch (\Throwable) {
                // 换下一把历史密钥。
            }
        }

        throw RegistrationException::invalid('联系人密文无法用当前或历史密钥解密。');
    }

    /**
     * @return array<int, string>
     */
    private function previousKeys(): array
    {
        $keys = config('ecosystem_registration.previous_app_keys', []);

        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }

        return array_values(array_filter(array_map(
            static fn ($key): string => trim((string) $key),
            (array) $keys,
        )));
    }

    /**
     * 与 Illuminate\Encryption\EncryptionServiceProvider::parseKey 一致：
     * `base64:` 前缀的 APP_KEY 必须先解码成原始 32 字节。
     */
    private function parseKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    /**
     * 手机号归一化：去掉空白/连字符/括号，允许一个前导 +。
     * 不猜测国家码，不做“同号变体”合并 —— 只用于查重键。
     */
    public function normalizePhone(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if ($digits === '' || strlen($digits) > 20) {
            return null;
        }

        // 国际号至少 7 位，本地号至少 5 位；过短不接受。
        if (strlen($digits) < 5) {
            return null;
        }

        return ($hasPlus ? '+' : '').$digits;
    }

    public function hashPhone(string $normalizedPhone): string
    {
        $key = (string) config('ecosystem_registration.pii_key', '');

        if ($key === '') {
            // 配置加载阶段不能可靠读取 app.key；运行时派生，禁止静默使用空键。
            $appKey = (string) config('app.key', '');

            if ($appKey === '') {
                throw RegistrationException::invalid('报名 PII 查重键未配置。');
            }

            $key = hash_hmac('sha256', 'ecosystem-registration-pii', $appKey, true);
        }

        return hash_hmac('sha256', $normalizedPhone, $key);
    }

    public function last4(string $normalizedPhone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $normalizedPhone) ?? '';

        return substr($digits, -4);
    }

    public function maskName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        if (mb_strlen($name) <= 1) {
            return '*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', mb_strlen($name) - 1);
    }

    public function maskPhone(?string $phone): string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';

        if (strlen($digits) <= 7) {
            return str_repeat('*', max(1, strlen($digits)));
        }

        return substr($digits, 0, 3).str_repeat('*', strlen($digits) - 7).substr($digits, -4);
    }

    /**
     * 列表/导出默认只输出遮罩；不需要解密即可展示。
     *
     * @return array{name: string, phone: string, city: string}
     */
    public function maskedContact(string $nameCipher, string $phoneCipher, ?string $city): array
    {
        return [
            'name'  => $this->maskName($this->decryptName($nameCipher)),
            'phone' => $this->maskPhone($this->decryptPhone($phoneCipher)),
            'city'  => (string) ($city ?? ''),
        ];
    }
}
