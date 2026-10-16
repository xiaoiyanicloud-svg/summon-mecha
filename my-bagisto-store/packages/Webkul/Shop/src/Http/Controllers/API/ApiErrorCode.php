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
    /** 一机一码(B-19):格式不合法(工信部编码为字母数字,长度受限)。 */
    public const DEVICE_NATIONAL_CODE_INVALID = 'DEVICE_NATIONAL_CODE_INVALID';
    /** 一机一码(B-19):该码已被别的设备占用 —— "一机一码"意味着全局唯一。 */
    public const DEVICE_NATIONAL_CODE_DUPLICATE = 'DEVICE_NATIONAL_CODE_DUPLICATE';
    /** 设备真相源(binding)缺失,需重新登记设备。 */
    public const DEVICE_BINDING_MISSING = 'DEVICE_BINDING_MISSING';
    public const DEVICE_NOT_OWNED = 'DEVICE_NOT_OWNED';

    // ── 技能与授权 ───────────────────────────────────────────────────────

    public const SKILL_NOT_FOUND = 'SKILL_NOT_FOUND';
    /** 传了不在**当前客户**授权范围内的 skill_id(工单/报案)。与 DEVICE_NOT_OWNED 同义。 */
    public const SKILL_NOT_OWNED = 'SKILL_NOT_OWNED';
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

    // ── 试用（B-3 · 计划 §4.7 / D4）──────────────────────────────────────

    /**
     * 该技能没有在线（agent）通道 → 不提供试用。
     *
     * 计划 D4：「仅限在线通道；人工安装通道技能无试用」。这不是"暂时不可用"，
     * 是**设计如此** —— 人工通道无法在 20 分钟后把技能从机器上收回来。
     */
    public const TRIAL_CHANNEL_UNSUPPORTED = 'TRIAL_CHANNEL_UNSUPPORTED';

    /** 同一用户、同一技能的试用冷却期内已试过（§4.15「每用户每技能 7 天内 1 次」）。 */
    public const TRIAL_LIMIT_REACHED = 'TRIAL_LIMIT_REACHED';

    /** 已买断该技能 —— 无需（也不该）再发一条 20 分钟的临时授权。 */
    public const TRIAL_ALREADY_OWNED = 'TRIAL_ALREADY_OWNED';

    // ── 风险告知留痕（B-5 · 计划 §4.14 / D6 三层责任）───────────────────

    /** high 风险技能：必须逐条勾选告知项后才允许继续（下单/下发）。 */
    public const RISK_ACK_REQUIRED = 'RISK_ACK_REQUIRED';

    /** 该技能没有可展示的风险分级（无已发布版本）。 */
    public const RISK_PROFILE_NOT_FOUND = 'RISK_PROFILE_NOT_FOUND';

    // ── 下发 ─────────────────────────────────────────────────────────────

    public const TASK_NOT_FOUND = 'TASK_NOT_FOUND';
    /**
     * 设备回报的状态流转不合法(对已 success 的任务报 failed 等)—— P9。
     *
     * ⚠ 此前这类被拒的回报**静默丢弃并仍返 200 ok**:端侧以为报成功了、云侧状态没动,
     *   端云永久分叉且没有任何痕迹。机器消费者必须能凭码区分"收到了"与"没被接受"。
     */
    public const TASK_TRANSITION_INVALID = 'TASK_TRANSITION_INVALID';
    /** Agent 部署通道未配置(缺 AGENT_KEY)。 */
    public const AGENT_CHANNEL_NOT_CONFIGURED = 'AGENT_CHANNEL_NOT_CONFIGURED';
    public const ENROLL_TOKEN_INVALID = 'ENROLL_TOKEN_INVALID';
    public const ENROLL_TOKEN_EXPIRED = 'ENROLL_TOKEN_EXPIRED';
    /** per-device 凭据无效/已吊销/已过期(§4.22 F1)。 */
    public const AGENT_CREDENTIAL_INVALID = 'AGENT_CREDENTIAL_INVALID';
    /** 设备身份与请求的 did 不一致(per-device 身份下不可冒充他人)。 */
    public const AGENT_IDENTITY_MISMATCH = 'AGENT_IDENTITY_MISMATCH';
    /** 拒绝降级:目标版本低于本机已装(§4.22 F1 版本单调 / B-13)。 */
    public const AGENT_DOWNGRADE_REJECTED = 'AGENT_DOWNGRADE_REJECTED';
    public const GRAY_RELEASE_NOT_FOUND = 'GRAY_RELEASE_NOT_FOUND';
    public const KILL_SWITCH_NOT_ENGAGED = 'KILL_SWITCH_NOT_ENGAGED';

    /**
     * 包完整性自检未过（任务卡 S-1）：盘上字节的**实时 sha256** ≠ 记录里的 `file_hash`。
     *
     * 下载端点**拒绝输出**并用此码明确报错 —— 宁可让这次下发失败，也绝不把
     * "跟记录对不上的字节"发给设备。与 `SKILL_ASSET_FILE_MISSING`（文件整个不见了）分开：
     * 那是"没东西"，这是"有东西，但不是该发的那一份"（被改动 / 损坏 / 串包）——
     * 处置完全不同，混成一个码会把排查引向错的地方。
     */
    public const PACKAGE_INTEGRITY_MISMATCH = 'PACKAGE_INTEGRITY_MISMATCH';

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

    /**
     * 编译(转换层)：**全部**目标机型都没能登记成功（D-9）。
     *
     * 起因：`POST /skill-supply/versions/{id}/compile` 此前无论 per-model 结果如何都返
     * 200 + "编译已登记。" —— 于是 kind 不支持 / 清单缺段这类**调用方可修正**的输入问题，
     * HTTP 层完全看不见，脚本消费者必须解析 body 才知道一件都没登记上。
     * 全失败时改用 422 + 本码（与 `SKILL_PACKAGE_INVALID` 同一处置逻辑）。
     */
    public const SKILL_COMPILE_NOTHING_REGISTERED = 'SKILL_COMPILE_NOTHING_REGISTERED';

    /** 版本当前状态不允许提审 —— 只有「草稿」/「被驳回」可提（堵重复提审已发布版本）。 */
    public const VERSION_NOT_SUBMITTABLE = 'VERSION_NOT_SUBMITTABLE';

    // ── 评价 ─────────────────────────────────────────────────────────────

    public const REVIEW_NOT_APPROVED = 'REVIEW_NOT_APPROVED';
    public const REVIEW_TRANSLATE_FAILED = 'REVIEW_TRANSLATE_FAILED';

    /** 一人一技能一条（加固 §四 A1）—— 第二次提交不是"失败"，是"已经评过了" */
    public const REVIEW_ALREADY_EXISTS = 'REVIEW_ALREADY_EXISTS';

    // ── 举报（加固 §四 A1）────────────────────────────────────────────────

    /** 举报对象（技能/评价）不存在 —— 举报必须落在真实对象上 */
    public const REPORT_TARGET_NOT_FOUND = 'REPORT_TARGET_NOT_FOUND';

    // ── 版本(后台) ──────────────────────────────────────────────────────

    public const VERSION_ARCHIVED = 'VERSION_ARCHIVED';
    public const VERSION_HAS_NO_ASSET = 'VERSION_HAS_NO_ASSET';

    /**
     * 声明为需落盘载体（file / motion_data / app_package / vendor_package）的交付物
     * 在存储上取不到 → 不得上架（见 `SkillPublisher::ERR_ASSET_FILE_MISSING`；D-9 起
     * 判据从"只认 file"放宽到全部需落盘载体）。
     *
     * ⚠ 与 `SKILL_ASSET_FILE_MISSING` 分开：那个是**下载时**发现文件没了（用户已经买了、
     *   要人工介入）；这个是**发布前置**就挡住了（还没卖出去，运营自己重传即可）。
     *   同一个现象、完全不同的处置路径，不能共用一个码。
     */
    public const ASSET_FILE_MISSING = 'ASSET_FILE_MISSING';

    /** 未通过发布闸门 —— 两条发布通道共用的同一理由码（见 Services\SkillAudit\SkillPublisher） */
    public const GATE_NOT_PASSED = 'GATE_NOT_PASSED';

    /**
     * 版本所属主体的**实名未通过审核** → 不得上架（任务卡 D-5 · 裁决 Q6）。
     * 与 GATE_NOT_PASSED 分开登记的意义：运营据此区分"开发者没实名"与"审核没过"，
     * 否则会被引去审核台找一个并不存在的审核问题。
     */
    public const OWNER_NOT_VERIFIED = 'OWNER_NOT_VERIFIED';

    /** 主体协议未签全 → 不得上架。与实名同属"发布前置"，故与 GATE_NOT_PASSED 分开。 */
    public const AGREEMENT_REQUIRED = 'AGREEMENT_REQUIRED';

    /** 版本的 seller_id 有值却查不到开发者主体 —— 归属异常（fail-closed），**不是**"平台自营"。 */
    public const OWNER_PROFILE_MISSING = 'OWNER_PROFILE_MISSING';

    /**
     * 审核通过之后交付物又变了 → 不得发布（任务卡 D-7 · ⑨）。
     *
     * ⚠ 与 `GATE_NOT_PASSED` 分开登记：那条要去**审核台**把审核做掉（队列里显示"未过"），
     *   而这条恰恰相反 —— 审核台显示的是"已通过"，只是那个"通过"对应的**是另一份包**。
     *   运营据此该去**商品编辑页**重新上传并重新提审，去审核台只会空转。
     *   混成一个码就是把排查引向一个不存在的问题。
     */
    public const PACKAGE_CHANGED_AFTER_REVIEW = 'PACKAGE_CHANGED_AFTER_REVIEW';

    /**
     * 已发布的版本不允许再上传/覆盖交付物（任务卡 D-7 · ⑨）——
     * 买家可能已经下载/安装了这一份，改它就是让"线上那一份"静默换字节。要改请发新版本。
     */
    public const VERSION_PUBLISHED_IMMUTABLE = 'VERSION_PUBLISHED_IMMUTABLE';

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
