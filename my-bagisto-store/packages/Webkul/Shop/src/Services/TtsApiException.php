<?php

namespace Webkul\Shop\Services;

use RuntimeException;

/**
 * 云端语音合成返回的**业务错误**(带 provider 与原始错误码)。
 *
 * 为什么要把错误码单独带出来:上层需要区分两类失败 ——
 *
 *   · **系统性**(资源包用尽 / 鉴权失败 / 服务未开通 / 参数非法)
 *     重试多少次都一样,应当**立刻放弃并告知用户**,而不是反复重试。
 *   · **临时性**(网络抖动 / 限流 / 服务端偶发)
 *     值得跳过这一句、继续念下一句。
 *
 * 只靠异常 message 做字符串匹配既脆弱又容易漏,所以用类型 + 错误码表达。
 *
 * ⚠ 语音合成现在有两个 provider(腾讯云 / 火山引擎),**两家的错误码规则完全不同**:
 *   腾讯是 `AuthFailure.SignatureFailure` 这种带点号的前缀码;
 *   火山是 `3001` 这种数字码。所以判定必须按 provider 分开 —— 用同一套规则去套
 *   另一家,必然误判。
 */
final class TtsApiException extends RuntimeException
{
    public const PROVIDER_TENCENT = 'tencent';

    public const PROVIDER_VOLCANO = 'volcano';

    /**
     * ⚠ 待确认:火山引擎 openspeech 的数字错误码含义是按公开资料整理的,
     *   本机环境无法访问官方文档核实,也没跑通过一次成功响应。
     *   这张表只影响"要不要重试",判错的代价是多发两次请求,不影响正确性。
     *   拿到真实密钥跑通后应据实修正。
     */
    /**
     * ⚠ 火山的码表**还没摸清**:实测缺 key 报的是 HTTP 400 +
     *   {"header":{"code":45000000,"message":"app key not found..."}} ——
     *   与旧版 3000 体系完全不同,而**成功码的取值也还没核实**(所以客户端刻意
     *   "不靠成功码判断成败",见 VolcanoTtsClient::extractAudio)。
     *
     * 因此这里采取**"一律视为系统性"**的保守策略:
     *   · 代价:偶发的服务端抖动会让这次朗读立刻放弃(而非重试 3 次)
     *   · 收益:配置类错误(缺 key、音色不存在)不会白刷 3 次注定失败的请求,
     *          用户也立刻收到提示
     *   两者最终都是"这次没念 + 弹一次提示",但后者少发两次无用请求。
     *
     * 等摸清码表后,把临时性的那些填进 TRANSIENT 白名单即可 —— 那里就是为此留的。
     */
    private const VOLCANO_TRANSIENT_CODES = [];

    /**
     * 响应里定位不到音频字段 —— 最可能是字段名与我们重建的不一致。
     *
     * ⚠ 把所有已知信息(解析出的键名、原始响应开头)都塞进异常,是为了让**一发探针
     *   就能看出真实的响应结构**。否则只会得到一句"合成失败",还得来回猜。
     */
    public static function unexpectedVolcanoResponse(string $rawBody, ?array $decoded, string $audioField = 'data'): self
    {
        return new self(
            self::PROVIDER_VOLCANO,
            'UnexpectedResponse',
            sprintf(
                '火山响应里没找到音频字段 `%s`。已解析出的键: %s。原始响应前 500 字符: %s',
                $audioField,
                $decoded === null ? '(整段无法解析为 JSON)' : '['.implode(', ', array_keys($decoded)).']',
                mb_substr($rawBody, 0, 500)
            )
        );
    }

    public function __construct(
        private readonly string $provider,
        private readonly string $providerCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function provider(): string
    {
        return $this->provider;
    }

    /** 云厂商的原始错误码,如 UnsupportedOperation.PkgExhausted 或 3001 */
    public function providerCode(): string
    {
        return $this->providerCode;
    }

    /** 是否是"重试也不会好"的系统性错误 */
    public function isSystemic(): bool
    {
        // 响应里没有预期的音频字段 —— 契约不符,重试不可能变好,只能改代码/配置
        if ($this->providerCode === 'UnexpectedResponse') {
            return true;
        }

        if ($this->provider === self::PROVIDER_VOLCANO) {
            return ! in_array($this->providerCode, self::VOLCANO_TRANSIENT_CODES, true);
        }

        // 腾讯:前缀匹配而非等值 —— 它的码带子类后缀(如 AuthFailure.SignatureFailure),
        // 而且以后可能新增子类,精确等值会漏。
        $prefixes = [
            'AuthFailure',            // 密钥错 / 签名错 / 时间偏差过大
            'UnauthorizedOperation',  // 服务未开通 / 无权限
            'UnsupportedOperation',   // 资源包用尽等
            'InvalidParameter',       // 参数或配置错(如音色 ID 不对)
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($this->providerCode, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
