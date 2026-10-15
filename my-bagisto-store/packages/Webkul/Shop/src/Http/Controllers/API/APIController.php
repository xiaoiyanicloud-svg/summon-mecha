<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;

/**
 * `api/*` 所有控制器的基类。
 *
 * ── 统一响应信封(X1) ──
 *
 *   成功:  { "success": true,  "message"?: string, "data"?: mixed, ...extra }
 *   失败:  { "success": false, "code": string, "message": string, ...extra }
 *
 * 为什么失败体里**保留** `success` 字段:在 X1 之前,TtsController / AsrController
 * 已经是 `{success,code,message}` 且前端(utils/tts.js、utils/voice.js)按 `success` 分支。
 * 统一信封不能顺手改掉这个契约 —— 所以 `{code,message}` 是子集,`success` 是超集,
 * 老前端(N)与新前端(N+1)都能读。**新增字段是加,不是改。**
 *
 * 状态码约定(与 TtsController / AsrController 类注释一致,不是新发明):
 *   · 请求本身非法(参数错 / 未认证 / 不存在 / 状态冲突)→ 明确 4xx。用 `reject()`(422)
 *     或 `fail($code, $message, 404)` 这类显式状态码。
 *   · 业务性失败(配额用尽 / 下游没听清 / 未配置)是**预期内的常态**,用 4xx 表达不准确
 *     → `fail()` 默认 200,由 `code` 区分。这类失败前端只做提示,不做协议层分支。
 *   · ⚠ 401 要慎用:utils/request.js 见到 401 会**清空登录态**。游客可用的入口
 *     (语音、AI 召唤)绝不能返 401,否则一次朗读把用户踢下线。
 *
 * 错误码一律取自 {@see ApiErrorCode}(单一事实源),禁止在控制器里手写字面量。
 */
class APIController extends Controller
{
    /**
     * 成功信封。
     *
     * @param  mixed  $data     业务数据;为 null 时不输出 `data` 键
     * @param  string  $message  提示文案;为空串时不输出 `message` 键
     * @param  array  $extra    额外顶层字段(如分页、计数)
     */
    protected function ok($data = null, string $message = '', array $extra = []): JsonResponse
    {
        $body = ['success' => true];

        if ($message !== '') {
            $body['message'] = $message;
        }

        if ($data !== null) {
            $body['data'] = $data;
        }

        return response()->json(array_merge($body, $extra));
    }

    /**
     * 失败信封。
     *
     * `$status` 默认 200 —— 见类注释:业务性失败是常态,只有"请求本身非法"才用 4xx。
     * 默认值刻意与 TtsController / AsrController 原有的私有 `fail()` 保持一致,
     * 这样把它们的样板提到基类**不改变任何既有行为**。
     *
     * @param  string  $code    取自 {@see ApiErrorCode}
     * @param  array  $extra    额外顶层字段(如 errors / data)
     */
    protected function fail(string $code, string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], $extra), $status);
    }

    /**
     * 请求本身非法 —— `fail(..., 422)` 的糖,对应 TtsController / AsrController 原来的
     * 私有 `reject()`。空文本 / 超长 / 缺文件 / 格式不支持走这里。
     */
    protected function reject(string $code, string $message, array $extra = []): JsonResponse
    {
        return $this->fail($code, $message, 422, $extra);
    }

    /**
     * `internal.key` 通道的调用方自称身份 —— 熔断 / 灰度 / 回滚这类平台端动作的操作者留痕。
     *
     * ⚠ 诚实边界:`internal.key` 是**共享密钥**,服务端**无法验证**这个自称。它只是
     *   "调用方自报是谁",不是认证身份(per-operator 凭证是目标形态)。
     *   故一律存成 `internal[:<自称>]` —— 前缀本身就写明"这是内部通道自报的",
     *   不会被误读成"已认证的管理员"。此前熔断把操作者写死 `'admin'`,
     *   比留空更坏:它看起来像有留痕。
     *
     * 返回值截到 32 字符,与 `skill_blocklist.created_by` 的列宽对齐(超宽在 MySQL
     * 严格模式下会直接写入失败 —— 那会让一次熔断因为"操作者名字太长"而失败)。
     */
    protected function internalOperator(Request $request): string
    {
        $raw = trim((string) $request->header('X-Internal-Operator', ''));

        // 只留可安全入库的字符(去掉换行/控制字符等 —— 它会被写进审计与日志)
        $raw = (string) preg_replace('/[^\p{L}\p{N}\-_.:@ ]/u', '', $raw);

        return $raw === '' ? 'internal' : mb_substr('internal:'.$raw, 0, 32);
    }

    /**
     * 本机游客标识 —— 由前端首次生成并持久化,随每个请求以 X-Guest-Key 头带来。
     *
     * 用途:把「游客订单」限定到产生它的那台设备。
     * 在此之前 guestIndex 用 whereNull('customer_id') 取单,会把所有人的游客单
     * 都返回给任何一个访客(A 能看到 B 的订单)—— 这个标识就是那个洞的补丁。
     *
     * 太短的 key 一律拒绝(要求 >= 16 位):否则别人可以拿 '1' 这种值去撞别人的订单。
     * 返回时截到 32 位,与 orders.guest_key 的列宽对齐。
     */
    protected function guestKey(Request $request): ?string
    {
        $key = (string) $request->header('X-Guest-Key', '');

        // 只保留 UUID / hex 这类安全字符
        $key = preg_replace('/[^A-Za-z0-9\-]/', '', $key);

        if (strlen($key) < 16) {
            return null;
        }

        return substr($key, 0, 32);
    }
}
