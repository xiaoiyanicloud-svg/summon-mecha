<?php

namespace Webkul\Shop\Http\Controllers\API;

/**
 * API 错误码登记表 —— X1「统一错误信封」的单一事实源。
 *
 * ── 为什么要有这张表 ──
 *   在此之前,`api/*` 的失败返回至少有 8 种结构:
 *     {message} 裸 / {message,code} / {success:false,message} / {success:false,code,message}
 *     / {message,errors} / {data,message} / abort(404) / 空串 401。
 *   结构不统一 → 前端只能一处处打补丁,且**机器消费者(Agent、开发者门户)拿不到稳定错误码**。
 *   本表把「码」收敛成一处:控制器一律 `$this->fail(ApiErrorCode::XXX, $message, $status)`,
 *   前端与 Agent 只认这里的值。
 *
 * ── 约定 ──
 *   · 值是**线上契约**,常量名只是本仓的引用方式。改名可以,**改值等于改契约**,须报批。
 *   · 命名:`<域>_<原因>`,全大写蛇形。域前缀与控制器/业务域对齐。
 *   · 码不做国际化:文案走 `message`,码给程序判断用。
 *   · 本表只登记「客户端可依据其做出不同处置」的码。纯透传异常文案的场景
 *     (如支付网关抛错)用通用码,详情进日志,不在这里逐个登记。
 *
 * 取值**刻意**保留了本仓历史字面量(SKILL_BLOCKED / MAX_ACTIVATIONS_REACHED /
 * INVALID_CREDENTIALS / AGREEMENT_INCOMPLETE / DEVELOPER_SELLER_MISSING /
 * SKILL_NOT_FOUND / VERSION_NOT_FOUND / DEVICE_NOT_OWNED / AMOUNT_MISMATCH /
 * CMS_PAGE_NOT_FOUND / AUDITOR_ID_NOT_ACCEPTED_HERE / ACCOUNT_LOCKED / NOT_FOUND),
 * 因为这些值已经发到线上,前端可能已按值分支 —— 统一信封不等于改名。
 */
final class ApiErrorCode
{
    // ── 通用 ──────────────────────────────────────────────────────────────

    /** 参数校验失败。extra 里带 `errors`(字段级)。 */
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    /** 资源不存在(通用)。仅在确实跨域不好归类时使用。 */
    public const NOT_FOUND = 'NOT_FOUND';

    /** 已登录但无权访问(通用)。 */
    public const FORBIDDEN = 'FORBIDDEN';

    /** 未登录或凭据失效。⚠ 前端见 401 会清登录态,慎用。 */
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    /** 状态冲突(重复提交 / 状态不允许当前操作)。 */
    public const CONFLICT = 'CONFLICT';

    /** 限流或锁定。 */
    public const RATE_LIMITED = 'RATE_LIMITED';

    /** 依赖的外部服务不可用(网关未配置 / 下游挂了)。 */
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    /** 服务端未预期错误。 */
    public const SERVER_ERROR = 'SERVER_ERROR';

    // ── 账号 ─────────────────────────────────────────────────────────────

    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    public const ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    public const ACCOUNT_NOT_ACTIVATED = 'ACCOUNT_NOT_ACTIVATED';
    public const ACCOUNT_NOT_VERIFIED = 'ACCOUNT_NOT_VERIFIED';

    // ── 设备 ─────────────────────────────────────────────────────────────

    public const DEVICE_NOT_FOUND = 'DEVICE_NOT_FOUND';
    /** Agent 侧:该 did 从未登记。与 DEVICE_NOT_FOUND(用户侧)分开,便于端侧自愈提示。 */
    public const DEVICE_NOT_REGISTERED = 'DEVICE_NOT_REGISTERED';
    public const DEVICE_SN_REQUIRED = 'DEVICE_SN_REQUIRED';
    public const DEVICE_SN_DUPLICATE = 'DEVICE_SN_DUPLICATE';
    /** 设备真相源(binding)缺失,需重新登记设备。 */
    public const DEVICE_BINDING_MISSING = 'DEVICE_BINDING_MISSING';
    public const DEVICE_NOT_OWNED = 'DEVICE_NOT_OWNED';

    // ── 技能与授权 ───────────────────────────────────────────────────────

    public const SKILL_NOT_FOUND = 'SKILL_NOT_FOUND';
    public const VERSION_NOT_FOUND = 'VERSION_NOT_FOUND';
    public const ENTITLEMENT_NOT_FOUND = 'ENTITLEMENT_NOT_FOUND';
    /** 授权已撤销 / 已过期 / 已达终态。 */
    public const ENTITLEMENT_REVOKED = 'ENTITLEMENT_REVOKED';
    /** 订单未支付 → 付费墙(M2)。 */
    public const ENTITLEMENT_UNPAID = 'ENTITLEMENT_UNPAID';
    /** 该授权还没绑定设备,先绑设备再安装。 */
    public const ENTITLEMENT_BINDING_REQUIRED = 'ENTITLEMENT_BINDING_REQUIRED';
    public const SKILL_ASSET_NOT_FOUND = 'SKILL_ASSET_NOT_FOUND';
    /** 交付物记录在库,但磁盘上的文件不见了 —— 需要人工介入,不是用户能纠正的错误。 */
    public const SKILL_ASSET_FILE_MISSING = 'SKILL_ASSET_FILE_MISSING';
    /** 激活次数用尽(D1)。 */
    public const MAX_ACTIVATIONS_REACHED = 'MAX_ACTIVATIONS_REACHED';
    /** 技能已被熔断,禁止新下发(D9)。 */
    public const SKILL_BLOCKED = 'SKILL_BLOCKED';
    /**
     * 授权已落库,但下发这一步没成功(通道未注册 / 磁盘缺件 / 通道自身报错)。
     *
     * ⚠ 与 SKILL_BLOCKED 的区别:熔断是"平台不许发",本码是"该发但没发成",用户可重试。
     *   绑定/认领类接口在**已经改库之后**才发现下发失败,所以不能假装整个请求失败(见 D7)。
     */
    public const SKILL_DELIVERY_FAILED = 'SKILL_DELIVERY_FAILED';
    public const SKILL_LOGIN_REQUIRED = 'SKILL_LOGIN_REQUIRED';

    // ── 下发 ─────────────────────────────────────────────────────────────

    public const TASK_NOT_FOUND = 'TASK_NOT_FOUND';
    /** Agent 部署通道未配置(缺 AGENT_KEY)。 */
    public const AGENT_CHANNEL_NOT_CONFIGURED = 'AGENT_CHANNEL_NOT_CONFIGURED';
    public const ENROLL_TOKEN_INVALID = 'ENROLL_TOKEN_INVALID';
    public const ENROLL_TOKEN_EXPIRED = 'ENROLL_TOKEN_EXPIRED';
    public const GRAY_RELEASE_NOT_FOUND = 'GRAY_RELEASE_NOT_FOUND';
    public const KILL_SWITCH_NOT_ENGAGED = 'KILL_SWITCH_NOT_ENGAGED';

    // ── 订单与支付 ───────────────────────────────────────────────────────

    public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
    /** 用户在结账页可纠正的校验失败(M7:此前被包成 500)。 */
    public const ORDER_VALIDATION_FAILED = 'ORDER_VALIDATION_FAILED';
    /** 结账分步(选配送/选支付)落库失败 —— 购物车状态变了或保存不成功。 */
    public const CHECKOUT_STEP_FAILED = 'CHECKOUT_STEP_FAILED';
    public const ORDER_CREATE_FAILED = 'ORDER_CREATE_FAILED';
    public const ORDER_NOT_CANCELABLE = 'ORDER_NOT_CANCELABLE';

    /** 结账校验逐条码(M7)—— 用户可据此自行纠正,前端按码给不同引导。 */
    public const ACCOUNT_SUSPENDED = 'ACCOUNT_SUSPENDED';
    public const ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';
    public const MINIMUM_ORDER_AMOUNT_NOT_MET = 'MINIMUM_ORDER_AMOUNT_NOT_MET';
    public const SHIPPING_ADDRESS_REQUIRED = 'SHIPPING_ADDRESS_REQUIRED';
    public const BILLING_ADDRESS_REQUIRED = 'BILLING_ADDRESS_REQUIRED';
    public const SHIPPING_METHOD_REQUIRED = 'SHIPPING_METHOD_REQUIRED';
    public const PAYMENT_METHOD_REQUIRED = 'PAYMENT_METHOD_REQUIRED';

    /** 游客单缺少 X-Guest-Key(太短或没带)。 */
    public const GUEST_KEY_REQUIRED = 'GUEST_KEY_REQUIRED';
    public const LEAD_ANCHOR_MISSING = 'LEAD_ANCHOR_MISSING';
    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';
    public const PAYMENT_FAILED = 'PAYMENT_FAILED';
    public const PAYMENT_GATEWAY_UNAVAILABLE = 'PAYMENT_GATEWAY_UNAVAILABLE';
    /** 回调金额与订单应付不一致(M3)。 */
    public const AMOUNT_MISMATCH = 'AMOUNT_MISMATCH';

    // ── 购物车 ───────────────────────────────────────────────────────────

    public const CART_ADD_FAILED = 'CART_ADD_FAILED';
    public const COMPARE_ALREADY_ADDED = 'COMPARE_ALREADY_ADDED';
    public const COUPON_INVALID = 'COUPON_INVALID';
    public const COUPON_ALREADY_APPLIED = 'COUPON_ALREADY_APPLIED';
    public const COUPON_NOT_FOUND = 'COUPON_NOT_FOUND';
    public const COUPON_APPLY_FAILED = 'COUPON_APPLY_FAILED';

    // ── 售后 / 工单 / 事故 / 通知 ────────────────────────────────────────

    public const AFTER_SALE_REJECTED = 'AFTER_SALE_REJECTED';
    public const TICKET_NOT_FOUND = 'TICKET_NOT_FOUND';
    public const INCIDENT_NOT_FOUND = 'INCIDENT_NOT_FOUND';
    public const NOTIFICATION_NOT_FOUND = 'NOTIFICATION_NOT_FOUND';
    public const WISHLIST_NOT_FOUND = 'WISHLIST_NOT_FOUND';

    // ── 内容与活动 ───────────────────────────────────────────────────────

    public const CMS_PAGE_NOT_FOUND = 'CMS_PAGE_NOT_FOUND';
    public const EVENT_NOT_FOUND = 'EVENT_NOT_FOUND';
    public const EVENT_ENDED = 'EVENT_ENDED';
    public const EVENT_ALREADY_REGISTERED = 'EVENT_ALREADY_REGISTERED';

    // ── AI 召唤 ──────────────────────────────────────────────────────────

    public const SUMMON_QUERY_REQUIRED = 'SUMMON_QUERY_REQUIRED';

    // ── 开发者门户 ───────────────────────────────────────────────────────

    public const AGREEMENT_INCOMPLETE = 'AGREEMENT_INCOMPLETE';
    public const DEVELOPER_SELLER_MISSING = 'DEVELOPER_SELLER_MISSING';
    public const SKILL_ASSET_UPLOAD_REJECTED = 'SKILL_ASSET_UPLOAD_REJECTED';

    // ── 供货管控(internal.key) ──────────────────────────────────────────

    public const AUDITOR_ID_NOT_ACCEPTED_HERE = 'AUDITOR_ID_NOT_ACCEPTED_HERE';
    public const SKILL_PACKAGE_INVALID = 'SKILL_PACKAGE_INVALID';
    public const SKILL_IMPORT_FAILED = 'SKILL_IMPORT_FAILED';
    public const SKILL_L0_AUDIT_FAILED = 'SKILL_L0_AUDIT_FAILED';

    // ── 评价 ─────────────────────────────────────────────────────────────

    public const REVIEW_NOT_APPROVED = 'REVIEW_NOT_APPROVED';
    public const REVIEW_TRANSLATE_FAILED = 'REVIEW_TRANSLATE_FAILED';

    // ── 版本(后台) ──────────────────────────────────────────────────────

    public const VERSION_ARCHIVED = 'VERSION_ARCHIVED';
    public const VERSION_HAS_NO_ASSET = 'VERSION_HAS_NO_ASSET';

    // ── 语音(契约见 TtsController / AsrController 类注释) ────────────────

    public const NO_TEXT = 'NO_TEXT';
    public const TOO_LONG = 'TOO_LONG';
    public const TTS_NOT_CONFIGURED = 'TTS_NOT_CONFIGURED';
    public const TTS_UNAVAILABLE = 'TTS_UNAVAILABLE';
    public const TTS_FAILED = 'TTS_FAILED';
    public const NO_AUDIO = 'NO_AUDIO';
    public const TOO_LARGE = 'TOO_LARGE';
    public const UNSUPPORTED_FORMAT = 'UNSUPPORTED_FORMAT';
    public const ASR_NOT_CONFIGURED = 'ASR_NOT_CONFIGURED';
    public const ASR_FAILED = 'ASR_FAILED';

    /**
     * 全部已登记的码。
     *
     * 用途:测试断言「控制器里出现的码都在表内」,防止有人随手写字面量。
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        static $codes = null;

        return $codes ??= array_values((new \ReflectionClass(static::class))->getConstants());
    }
}
