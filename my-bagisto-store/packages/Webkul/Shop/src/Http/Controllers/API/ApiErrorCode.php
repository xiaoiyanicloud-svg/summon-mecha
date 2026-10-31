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

    public const SKILL_UNAVAILABLE = 'SKILL_UNAVAILABLE';

    public const SKILL_ORDER_TYPE_MISMATCH = 'SKILL_ORDER_TYPE_MISMATCH';
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
    /**
     * 该版本存在多个文件类交付物，调用方没有指定要哪一个。
     *
     * 与 SKILL_ASSET_NOT_FOUND 分开：那个是"没有包"，这个是"有多个、无法唯一确定"。
     * 平台**不得**擅自取排序第一个（那等于把发哪份字节交给排序实现决定），
     * 必须让调用方显式给 asset_id / carrier_type+sort_order，或先在上游收敛产物。
     */
    public const SKILL_ASSET_AMBIGUOUS = 'SKILL_ASSET_AMBIGUOUS';
    /**
     * 适配证据写入被拒：证据必须钉住**真实存在且属于该版本**的产物。
     *
     * 典型场景：显式 `asset_hash` 与库内 `skill_assets.file_hash` 不一致，
     * 或哈希在该版本里没有唯一命中。绝不以调用方口径覆盖库内事实 ——
     * 否则事后可以把"验证过"挂到另一份字节上。
     */
    public const SKILL_COMPATIBILITY_EVIDENCE_INVALID = 'SKILL_COMPATIBILITY_EVIDENCE_INVALID';
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
    /** 配件下单:商品不属于配件分类 / 未上架 / 不是可售配件。 */
    public const ACCESSORY_ORDER_TYPE_MISMATCH = 'ACCESSORY_ORDER_TYPE_MISMATCH';
    /** 配件下单:配件目录当前不可用(分类未配置)。 */
    public const ACCESSORY_UNAVAILABLE = 'ACCESSORY_UNAVAILABLE';
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

    /**
     * G1-10：活动已撤稿（`disposition=retracted`，终态）且曾经发布过。
     *
     * 与 `EVENT_NOT_FOUND` 分开：客户端展示中性撤稿说明并停止重试，
     * 不回显旧标题/正文/摘要/来源/媒体。从未发布过的 retracted 仍返回 404。
     */
    public const EVENT_UNAVAILABLE = 'EVENT_UNAVAILABLE';
    public const EVENT_ENDED = 'EVENT_ENDED';
    /** 赛事存在但当前不在 registering 阶段(upcoming/ongoing),写入口必须拒绝。 */
    public const EVENT_NOT_REGISTERING = 'EVENT_NOT_REGISTERING';
    public const EVENT_ALREADY_REGISTERED = 'EVENT_ALREADY_REGISTERED';

    // ── G2 报名（稳定错误码，前端据此分支）────────────────────────────
    /** 站内报名未开放 / 全局双闸或 scope 未激活。 */
    public const REGISTRATION_DISABLED = 'REGISTRATION_DISABLED';
    /** 报名尚未开始。 */
    public const REGISTRATION_NOT_OPEN = 'REGISTRATION_NOT_OPEN';
    /** 报名已截止 / scope 已关闭。 */
    public const REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';
    /** 容量已满（含 capacity=0）。 */
    public const REGISTRATION_FULL = 'REGISTRATION_FULL';
    /** 当前身份已有有效轮次，不能重复提交。 */
    public const REGISTRATION_ALREADY_ACTIVE = 'REGISTRATION_ALREADY_ACTIVE';
    /** 同 request_key 不同载荷，或同 key 被用于不同目标。 */
    public const REGISTRATION_REQUEST_CONFLICT = 'REGISTRATION_REQUEST_CONFLICT';
    /** 乐观并发 / 轮次已变化。 */
    public const REGISTRATION_VERSION_STALE = 'REGISTRATION_VERSION_STALE';
    /** 当前状态不允许该动作（已取消、已签到、旧轮次等）。 */
    public const REGISTRATION_TRANSITION_INVALID = 'REGISTRATION_TRANSITION_INVALID';
    /** 人工审核已截止，不能再批准。 */
    public const REGISTRATION_REVIEW_CLOSED = 'REGISTRATION_REVIEW_CLOSED';
    /** 达到最大报名次数。 */
    public const REGISTRATION_ATTEMPT_LIMIT = 'REGISTRATION_ATTEMPT_LIMIT';
    /** 报名不存在或不属于当前客户。 */
    public const REGISTRATION_NOT_FOUND = 'REGISTRATION_NOT_FOUND';
    /** 无活动范围/能力。 */
    public const REGISTRATION_FORBIDDEN = 'REGISTRATION_FORBIDDEN';
    /** 报名参数/政策配置不合法。 */
    public const REGISTRATION_VALIDATION_ERROR = 'REGISTRATION_VALIDATION_ERROR';

    /** 签到 token 无效/过期/已吊销/轮次不匹配。 */
    public const CHECKIN_TOKEN_INVALID = 'CHECKIN_TOKEN_INVALID';
    /** 当前活动/轮次不允许签到。 */
    public const CHECKIN_NOT_ALLOWED = 'CHECKIN_NOT_ALLOWED';
    /** 变更确认已截止。 */
    public const CHANGE_ACK_CLOSED = 'CHANGE_ACK_CLOSED';
    /** 导出文件已过期/被撤销。 */
    public const EXPORT_EXPIRED = 'EXPORT_EXPIRED';
    /** 无导出权限或活动范围。 */
    public const EXPORT_FORBIDDEN = 'EXPORT_FORBIDDEN';
    /** G0:旧站内报名写入口默认关闭，客户端应展示原因而不是重试创建。 */
    public const EVENT_REGISTRATION_DISABLED = 'EVENT_REGISTRATION_DISABLED';
    /**
     * G1-08 乐观并发：调用方 `expected_row_version` 与库内不一致。
     *
     * 客户端处置是“重取最新版本后人工合并”，不是重放 —— 与 EVENT_REGISTRATION_DISABLED
     * 的“不重试创建”同属 409/403 的稳定分支，但原因不同，故单独登记。
     */
    public const EVENT_REVISION_CONFLICT = 'EVENT_REVISION_CONFLICT';

    /**
     * G1-03：活动来源三字段缺失/不完整、枚举未知、来源与平台角色组合非法、
     * 或（发布前）官方来源 URL 缺失/非法。
     *
     * 客户端处置是“补齐主办方与来源资料后重新提交”，不是重试。
     */
    public const EVENT_SOURCE_REQUIRED = 'EVENT_SOURCE_REQUIRED';

    /**
     * G1-03：主办方未通过核验、处于 rejected/suspended，或当前来源/角色组合
     * 不在发布放行名单（B-01/B-03 未签署前只放行 platform + organizer）。
     *
     * 与 EVENT_SOURCE_REQUIRED 分开：资料齐但主体没核验，去补资料没有用，
     * 必须先完成核验；混成一个码会把排查引向错误动作。
     */
    public const EVENT_ORGANIZER_UNVERIFIED = 'EVENT_ORGANIZER_UNVERIFIED';

    /**
     * G1-04：活动时间非法 —— 时区不是合法 IANA 名称、结束早于开始、
     * 起止只给了一半，或平台活动缺少必填时间。
     *
     * 客户端处置是“修正时间或改为待定”，不是重试；与来源/主体缺失分开登记，
     * 否则前端会把时间错误引导到补主办方资料上。
     */
    public const EVENT_TIME_INVALID = 'EVENT_TIME_INVALID';

    /**
     * G1-04：当前状态不允许该修订/指针动作 —— 非 draft revision 改内容、
     * published 指针在本批提前写入、或试图删除审计证据。
     *
     * 客户端处置是刷新状态后按合法动作重做（例如新建 revision_no + 1），不重放原请求。
     */
    public const EVENT_TRANSITION_INVALID = 'EVENT_TRANSITION_INVALID';

    /**
     * G1-05：slug 已被现有文章占用，或文章已撤稿但 slug 永久保留。
     *
     * 客户端处置是换一个 slug，**不得**覆盖、静默加后缀或复用撤稿 slug。
     */
    public const CONTENT_SLUG_TAKEN = 'CONTENT_SLUG_TAKEN';

    /**
     * G1-05：内容来源不完整或权利依据不匹配 —— 缺来源名、原文 URL、
     * 核验时间或权利依据，或 `source_type` 与 `rights_basis` 组合非法。
     *
     * 客户端处置是补齐来源后重新提交，不是重试原请求。
     */
    public const CONTENT_SOURCE_REQUIRED = 'CONTENT_SOURCE_REQUIRED';

    /**
     * G1-05：内容乐观并发失败 —— 调用方 `expected_row_version` 与库内不一致。
     *
     * 与 `EVENT_REVISION_CONFLICT` 同属 409 的“重取后人工合并”，但域不同，
     * 前端与后台据此定位到文章而不是活动。
     */
    public const CONTENT_REVISION_CONFLICT = 'CONTENT_REVISION_CONFLICT';

    /**
     * G1-05：内容状态不允许该动作 —— 非 draft 文章/修订被编辑、
     * 首次发布后改 slug、G1-05 提前写 published 指针，或删除审计证据。
     *
     * 客户端处置是刷新状态后按合法动作重做（例如新建 revision_no + 1），不重放。
     */
    public const CONTENT_TRANSITION_INVALID = 'CONTENT_TRANSITION_INVALID';

    /**
     * G1-10：文章不存在，或未发布 / 待审 / 已批准未发布 / 已排期 / 暂缓。
     *
     * 公开端刻意不区分：统一 404、统一文案与结构，不泄露存在性。
     */
    public const CONTENT_NOT_FOUND = 'CONTENT_NOT_FOUND';

    /**
     * G1-10：文章已撤稿（`disposition=retracted`，终态）且曾经发布过。
     *
     * 客户端展示中性撤稿说明并停止重试；不回显旧标题/正文/摘要/来源/媒体。
     */
    public const CONTENT_UNAVAILABLE = 'CONTENT_UNAVAILABLE';

    // ── 分发与收藏（G4-02）────────────────────────────────────────────────

    /**
     * G4-02：收藏能力关闭。
     *
     * GET 收藏列表在关闭时返回空列表（不报错，页面可正常空态）；
     * POST/DELETE 明确 403，避免客户端以为写入成功。
     */
    public const ECOSYSTEM_FAVORITES_DISABLED = 'ECOSYSTEM_FAVORITES_DISABLED';

    /**
     * G4-02：收藏目标当前不可公开访问。
     *
     * 未发布 / 草稿 / 撤稿 / 不存在统一用该码与同一文案，不泄露存在性或旧标题。
     */
    public const FAVORITE_TARGET_NOT_FOUND = 'FAVORITE_TARGET_NOT_FOUND';

    /** G4-02：单用户收藏数达到上限。 */
    public const FAVORITE_LIMIT_REACHED = 'FAVORITE_LIMIT_REACHED';

    /** G4-02：target_type 不在 event/content 白名单。 */
    public const FAVORITE_TYPE_INVALID = 'FAVORITE_TYPE_INVALID';

    // ── 关联（G4-03）──────────────────────────────────────────────────────

    /** G4-03：关联能力关闭（详情不返回 related，Admin 仍可维护配置）。 */
    public const ECOSYSTEM_RELATIONS_DISABLED = 'ECOSYSTEM_RELATIONS_DISABLED';

    /** G4-03：关联目标不存在或当前不可展示。 */
    public const RELATION_TARGET_INVALID = 'RELATION_TARGET_INVALID';

    // ── 媒体（G1-06）──────────────────────────────────────────────────────

    /**
     * G1-06：媒体上传 / 审核失败 —— 真实 MIME 不允许、扩展名与 MIME 不符、
     * 图片解码或尺寸不合法、PDF 头部缺失、大小超限、sha256 重复，
     * 或当前状态不允许该审核动作。
     *
     * 客户端处置是换一份合法文件 / 新建媒体重新审核，不是重试原请求。
     */
    public const MEDIA_INVALID = 'MEDIA_INVALID';

    /**
     * G1-06：公开媒体不可访问 —— 域开关关闭、引用或媒体未审核、
     * 父 revision / 父对象未发布、文件缺失，统一 404。
     *
     * 刻意不区分原因：公开端不得泄露草稿 / 待审 / 暂缓媒体的存在性。
     */
    public const MEDIA_NOT_FOUND = 'MEDIA_NOT_FOUND';

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
     * 版本已经是 published —— 对已发布版本再调 publish 的明确结果（S-3b）。
     *
     * 此前这条路径会在状态机里撞 `published → published`，抛 RuntimeException → **HTTP 500**。
     * 下架是**商品级**停售（product.status=0），版本仍是 published，复售应走商品级
     * 「重新上架」(relist)，不是重复 publish。500 既不告诉调用方"版本没丢"，
     * 也不告诉他该走哪个动作；本码把这条路径变成可分支的结果。
     */
    public const VERSION_ALREADY_PUBLISHED = 'VERSION_ALREADY_PUBLISHED';

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

    // ── G3 人工赛务（稳定错误码，前端/后台据此分支）──────────────────

    /** 赛务写能力总闸关闭（`ecosystem_competition.write_enabled=false`）。 */
    public const COMPETITION_DISABLED = 'COMPETITION_DISABLED';
    /** 赛项/届次/系列不存在或未公开。 */
    public const COMPETITION_NOT_FOUND = 'COMPETITION_NOT_FOUND';
    /** 赛项（track）不存在。与 NOT_FOUND 分开：前端可提示“赛项已关闭/删除”，不是整个赛务不可用。 */
    public const COMPETITION_TRACK_NOT_FOUND = 'COMPETITION_TRACK_NOT_FOUND';
    /** 赛项存在但当前不是 active。 */
    public const COMPETITION_TRACK_NOT_ACTIVE = 'COMPETITION_TRACK_NOT_ACTIVE';
    /** 规则契约不完整/未确认；不得由代码补默认策略。 */
    public const COMPETITION_RULES_UNCONFIRMED = 'COMPETITION_RULES_UNCONFIRMED';
    /** 当届成员/资格/机器人声明不满足已冻结规则。 */
    public const COMPETITION_ELIGIBILITY_FAILED = 'COMPETITION_ELIGIBILITY_FAILED';
    /** 队伍不存在或不属于当前客户可见范围。 */
    public const COMPETITION_TEAM_NOT_FOUND = 'COMPETITION_TEAM_NOT_FOUND';
    /** 当前客户不是队长/有效成员，无权执行该动作。 */
    public const COMPETITION_TEAM_FORBIDDEN = 'COMPETITION_TEAM_FORBIDDEN';
    /** 赛项内队伍名已存在。 */
    public const COMPETITION_TEAM_NAME_TAKEN = 'COMPETITION_TEAM_NAME_TAKEN';
    /** 队伍人数已达规则上限。 */
    public const COMPETITION_TEAM_FULL = 'COMPETITION_TEAM_FULL';
    /** 成员状态不允许该动作（重复接受、已离开等）。 */
    public const COMPETITION_TEAM_MEMBER_STATE = 'COMPETITION_TEAM_MEMBER_STATE';
    /** 邀请码无效/已使用/已撤销。 */
    public const COMPETITION_INVITE_INVALID = 'COMPETITION_INVITE_INVALID';
    /** 邀请码已过期。 */
    public const COMPETITION_INVITE_EXPIRED = 'COMPETITION_INVITE_EXPIRED';
    /** 规则不允许一人多队。 */
    public const COMPETITION_MULTI_TEAM_NOT_ALLOWED = 'COMPETITION_MULTI_TEAM_NOT_ALLOWED';
    /** 参赛条目不存在/不属于当前队伍。 */
    public const COMPETITION_ENTRY_NOT_FOUND = 'COMPETITION_ENTRY_NOT_FOUND';
    /** 参赛条目尚未确认/已撤回，不能提交作品。 */
    public const COMPETITION_ENTRY_NOT_ACTIVE = 'COMPETITION_ENTRY_NOT_ACTIVE';
    /** 参赛快照已冻结，不能直接改写。 */
    public const COMPETITION_ENTRY_FROZEN = 'COMPETITION_ENTRY_FROZEN';
    /** 作品不存在或不属于当前队伍。 */
    public const COMPETITION_SUBMISSION_NOT_FOUND = 'COMPETITION_SUBMISSION_NOT_FOUND';
    /** 作品提交已截止/未配置截止。 */
    public const COMPETITION_SUBMISSION_CLOSED = 'COMPETITION_SUBMISSION_CLOSED';
    /** 作品版本冲突（expected_row_version 不一致）。 */
    public const COMPETITION_SUBMISSION_VERSION_STALE = 'COMPETITION_SUBMISSION_VERSION_STALE';
    /** 已提交版本不可修改。 */
    public const COMPETITION_SUBMISSION_IMMUTABLE = 'COMPETITION_SUBMISSION_IMMUTABLE';
    /** 附件类型/大小/数量/路径不合法。 */
    public const COMPETITION_ATTACHMENT_INVALID = 'COMPETITION_ATTACHMENT_INVALID';
    /** 附件不存在或无权读取。 */
    public const COMPETITION_ATTACHMENT_NOT_FOUND = 'COMPETITION_ATTACHMENT_NOT_FOUND';
    /** 赛程导入行不合法；extra 带逐行错误。 */
    public const COMPETITION_SCHEDULE_INVALID = 'COMPETITION_SCHEDULE_INVALID';
    /** 已发布阶段/场次不允许被导入覆盖。 */
    public const COMPETITION_SCHEDULE_PUBLISHED = 'COMPETITION_SCHEDULE_PUBLISHED';
    /** 通用乐观并发失败。 */
    public const COMPETITION_VERSION_STALE = 'COMPETITION_VERSION_STALE';
    /** 赛务参数/配置不合法。 */
    public const COMPETITION_VALIDATION_ERROR = 'COMPETITION_VALIDATION_ERROR';
    /** 个人报名入口不能用于 team 计量单位的 scope。 */
    public const COMPETITION_UNIT_MISMATCH = 'COMPETITION_UNIT_MISMATCH';
    /** 成绩记录不存在。 */
    public const COMPETITION_RESULT_NOT_FOUND = 'COMPETITION_RESULT_NOT_FOUND';
    /** 当前成绩状态不允许该操作。 */
    public const COMPETITION_RESULT_STATE = 'COMPETITION_RESULT_STATE';
    /** 录分人与复核发布人未分离。 */
    public const COMPETITION_DUTY_SEPARATION = 'COMPETITION_DUTY_SEPARATION';
    /** 存在未确定名次，不能发布。 */
    public const COMPETITION_RANKING_REQUIRED = 'COMPETITION_RANKING_REQUIRED';
    /** 排名数据不合法。 */
    public const COMPETITION_RANKING_INVALID = 'COMPETITION_RANKING_INVALID';
    /** 申诉不存在。 */
    public const COMPETITION_APPEAL_NOT_FOUND = 'COMPETITION_APPEAL_NOT_FOUND';
    /** 无权提交/查看该申诉。 */
    public const COMPETITION_APPEAL_FORBIDDEN = 'COMPETITION_APPEAL_FORBIDDEN';
    /** 申诉已过期或状态不允许。 */
    public const COMPETITION_APPEAL_CLOSED = 'COMPETITION_APPEAL_CLOSED';
    /** 申诉证据类型/大小不合法。 */
    public const COMPETITION_APPEAL_EVIDENCE_INVALID = 'COMPETITION_APPEAL_EVIDENCE_INVALID';
    /** 成绩版本已变化，需重新裁决。 */
    public const COMPETITION_APPEAL_RESULT_CHANGED = 'COMPETITION_APPEAL_RESULT_CHANGED';
    /** 奖项不存在。 */
    public const COMPETITION_AWARD_NOT_FOUND = 'COMPETITION_AWARD_NOT_FOUND';
    /** 奖项状态不允许该操作。 */
    public const COMPETITION_AWARD_STATE = 'COMPETITION_AWARD_STATE';
    /** 展示内容不存在。 */
    public const COMPETITION_SHOWCASE_NOT_FOUND = 'COMPETITION_SHOWCASE_NOT_FOUND';
    /** 展示状态不允许该操作。 */
    public const COMPETITION_SHOWCASE_STATE = 'COMPETITION_SHOWCASE_STATE';
    /** 队长尚未授权当前作品版本公开展示。 */
    public const COMPETITION_CONSENT_REQUIRED = 'COMPETITION_CONSENT_REQUIRED';
    public const SCENE_UNAVAILABLE = 'SCENE_UNAVAILABLE';
    public const SCENE_REQUIRED = 'SCENE_REQUIRED';
    public const SCENE_PRODUCT_UNAVAILABLE = 'SCENE_PRODUCT_UNAVAILABLE';


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
