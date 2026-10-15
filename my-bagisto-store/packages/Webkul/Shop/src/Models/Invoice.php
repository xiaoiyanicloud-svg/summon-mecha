<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 发票 / 开票登记（M9）。
 *
 * ⚠ **表名是单数 `invoice`**，与 Bagisto 原生的 `invoices`（复数，订单发货单/商业发票）
 *   完全不是一回事，两者不要混：
 *     - `invoices`        —— 电商口径的"订单已开票"，参与订单状态机（`invoice_state=paid`）
 *     - `invoice`（本表）  —— 中国的**税务发票（开票）**：抬头、税号、金额、开具状态
 *   本项目要的是后者。原生的那个不能承载"抬头/税号"，也不该被拿来当开票台账。
 *
 * ⚠ **当前只到"登记"为止，没有"开具"**：
 *   支付成功后自动落一条 `applied`（已登记）记录，让运营在后台看得到"这笔订单要开票"。
 *   真正的开具（对接税控/开票服务商、产出 PDF 落到 `file_path`、回填 `issued_at`）
 *   **未接入**，因此后台**刻意不提供"标记为已开具"的按钮** —— 那会造出一批没有税务
 *   依据的"已开票"记录，比没有记录更坏（本项目对"把没做的显示成做了"的立场见
 *   `OpsMetricsService::notConnected()`）。
 */
class Invoice extends Model
{
    protected $table = 'invoice';

    /**
     * 抬头类型。当前自动登记一律 `personal`：用户侧还没有"填抬头/税号"的入口
     * （见类注释的遗留说明），所以我们**不知道**他是不是要开公司票，不能替他猜。
     */
    public const TYPE_PERSONAL = 'personal';

    public const TYPE_COMPANY = 'company';

    /**
     * 开票状态。
     *
     *   applied —— 已登记待开具（本项目当前唯一会写入的状态）
     *   issued  —— 已开具（需税务侧回执 + `file_path`，**未接入**）
     */
    public const STATUS_APPLIED = 'applied';

    public const STATUS_ISSUED = 'issued';

    protected $fillable = [
        'customer_id',
        'order_ids',
        'type',
        'title',
        'tax_no',
        'amount',
        'status',
        'file_path',
        'issued_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        // `order_ids` 是 json 列：设计上**一张发票可以合并多张订单**（列名是复数），
        // 所以它没有、也不该有一个"唯一订单号"直挂列。
        'order_ids'   => 'array',
        'amount'      => 'float',
        'issued_at'   => 'datetime',
    ];

    /**
     * 本记录覆盖了哪些订单（`order_ids` 为 null 时返回空数组）。
     *
     * @return int[]
     */
    public function orderIds(): array
    {
        return array_map('intval', (array) ($this->order_ids ?? []));
    }
}
