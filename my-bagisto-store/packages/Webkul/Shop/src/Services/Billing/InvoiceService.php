<?php

namespace Webkul\Shop\Services\Billing;

use Webkul\Shop\Models\Invoice;

/**
 * 开票登记（M9）。
 *
 * **这一版只做"登记"，不做"开具"** —— 支付成功 ⇒ 落一条 `applied` 记录，
 * 让运营在后台看得到这笔订单要开票。税控开具/PDF/回执未接入（见 `Invoice` 类注释）。
 *
 * ⚠ **调用位置与事务边界是刻意的**：本方法由支付回调**在它自己的事务内**调用
 *   （`PaymentCallbackController`,紧随「翻订单状态 + 交付」之后）。理由：
 *     - 与 M7-2 的退款网关**相反**：那里是"外部调用不可回滚，所以必须移出事务"；
 *       这里是**本地库写，可回滚**，不存在"钱出去了账没落"的问题 ——
 *       不能拿同一条原则套。
 *     - 与 M7-1 同源：「订单已支付」与「该订单的账目记录都在」是**同一个业务断言**，
 *       要么一起提交、要么一起回滚。"已交付但查无开票登记"正是 D3 那类
 *       "主链路成功、旁路静默缺失"的缺陷形状，不该在这里再制造一次。
 *
 * ⚠ **幂等靠调用方的行锁，不是靠唯一键**：
 *   `invoice.order_ids` 是 json 列（设计上一张发票可合并多张订单），
 *   **没法加唯一键**。所以这里的"先查再插"成立的前提是 —— 调用它的事务已经持有
 *   该订单行的写锁（M7-1 的 `lockForUpdate`），并发回调被串行化；且回调的幂等分支
 *   里根本不会走到这里。两道一起才等于"一张订单一条登记"。
 *   ⚠ 遗留边界：将来若开放**人工补开**（并发来源不再只有回调），这层保证就失效了，
 *     那时必须先给 `invoice` 加一个直挂的订单号列 + 唯一键（见交付说明的遗留项）。
 */
class InvoiceService
{
    /**
     * 为一张已支付订单登记开票（幂等）。
     *
     * @param  object  $order  订单模型（只用到 id / customer_id / 客户姓名 / grand_total）
     * @return Invoice|null  返回 null 表示**这笔订单开不了票**：游客单没有抬头主体
     */
    public function issueForOrder($order): ?Invoice
    {
        $customerId = (int) ($order->customer_id ?? 0);

        // 游客单：`invoice.customer_id` 非空，且"给谁开票"本来就没有主体 ——
        // 不是失败，是这笔订单**开不出来**。此处刻意不 report()：
        // 游客单是合法状态，把它记成异常只会污染日志。
        if ($customerId <= 0) {
            return null;
        }

        $orderId = (int) $order->id;

        // 幂等：已登记过就原样返回，不新建第二条
        $existing = $this->forOrder($orderId);

        if ($existing) {
            return $existing;
        }

        return Invoice::create([
            'customer_id' => $customerId,
            'order_ids'   => [$orderId],
            'type'        => Invoice::TYPE_PERSONAL,
            // 抬头取订单上快照的客户姓名：客户被删/改名后，历史发票抬头不该跟着变
            'title'       => $this->titleFor($order, $customerId),
            // 税号只有用户填了才有 —— 当前没有用户侧开票入口，所以恒空。
            // 不填占位符：空白代表"还没问过用户"，不是"用户是个人"。
            'tax_no'      => null,
            // 四位小数键（decimal(12,4)）：与列精度对齐，别在这里先round(…, 2)把位数丢掉
            'amount'      => round((float) $order->grand_total, 4),
            'status'      => Invoice::STATUS_APPLIED,
            'file_path'   => null,
            'issued_at'   => null,
        ]);
    }

    /**
     * 这张订单有没有登记过开票。
     *
     * 取最早一条（`orderBy('id')`）：万一历史上出现过重复登记，页面上要展示的
     * 也是那笔真实存在的第一张，而不是随查询计划变化的一条。
     */
    public function forOrder(int $orderId): ?Invoice
    {
        return Invoice::query()
            ->whereJsonContains('order_ids', $orderId)
            ->orderBy('id')
            ->first();
    }

    /**
     * 抬头：优先取订单上的客户姓名快照，退化成客户 id。
     *
     * ⚠ 只取**订单上快照的**姓名（`orders.customer_first_name/last_name`），
     *   不去 join `customers`：发票抬头是开票当时的事实，客户后来改名/注销
     *   都不该改变历史抬头。
     */
    protected function titleFor($order, int $customerId): string
    {
        $name = trim(((string) ($order->customer_first_name ?? '')).' '.((string) ($order->customer_last_name ?? '')));

        return $name !== '' ? $name : '客户#'.$customerId;
    }
}
