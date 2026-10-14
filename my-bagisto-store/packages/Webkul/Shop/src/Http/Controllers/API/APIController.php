<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;

class APIController extends Controller
{
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
