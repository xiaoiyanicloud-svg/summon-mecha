@extends('shop::merchant.layouts.app')

@section('title', '商家工作台')

@section('content')
@php
    $statusMap = [
        'pending_shipment'    => ['label' => '待出库发货', 'class' => 'badge-amber'],
        'shipped'             => ['label' => '已发货在途', 'class' => 'badge-blue'],
        'in_use'              => ['label' => '在租使用中', 'class' => 'badge-blue'],
        'returned_inspecting' => ['label' => '归还待验机', 'class' => 'badge-amber'],
        'inspected'           => ['label' => '已验机定损', 'class' => 'badge-green'],
        'completed'           => ['label' => '履约已完结', 'class' => 'badge-green'],
        'cancelled'           => ['label' => '已取消/已撤单', 'class' => 'badge-red'],
    ];
    $ticketTypeMap = [
        'maintenance'   => ['label' => '设备维保检修', 'class' => 'badge-amber'],
        'repair'        => ['label' => '客户报修单', 'class' => 'badge-blue'],
        'exchange'      => ['label' => '换货申请单', 'class' => 'badge-blue'],
        'return_refund' => ['label' => '退货退款单', 'class' => 'badge-red'],
    ];
    $ticketStatusMap = [
        'open'       => ['label' => '待处理', 'class' => 'badge-amber'],
        'processing' => ['label' => '检修处理中', 'class' => 'badge-blue'],
        'resolved'   => ['label' => '已办结', 'class' => 'badge-green'],
        'rejected'   => ['label' => '已驳回', 'class' => 'badge-gray'],
    ];
    $lockTypeMap = [
        'restock'             => '初始建账 / 补货',
        'stocktake'           => '人工盘点校准',
        'sale_lock'           => '售卖下单扣减',
        'rental_lock'         => '租赁下单锁库',
        'return_release'      => '归还验机释放',
        'maintenance_lock'    => '转入维保锁定',
        'maintenance_release' => '维保修复释放',
    ];
@endphp

<div class="page-header">
    <div>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h1 class="page-title">{{ $currentMerchantShop->shop_name }}</h1>
            <span class="badge badge-green"><span class="badge-dot"></span>营业中</span>
        </div>
        <div class="page-desc">
            商户主体：{{ $currentMerchantProfile->company_name ?? '—' }} · 独立核算节点 <code>#{{ $currentMerchantShop->seller_id }}</code> · 金额统一按整数分精准核算
        </div>
    </div>

    <div class="page-actions">
        @if($activeTab === 'products')
            <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-product')">+ 发布新商品</button>
        @elseif($activeTab === 'inventory')
            <button type="button" class="btn btn-secondary" onclick="openMerchantModal('modal-create-warehouse')">+ 新增履约仓库</button>
        @elseif($activeTab === 'orders')
            <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-order')">+ 登记履约订单</button>
        @elseif($activeTab === 'after_sales')
            <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-ticket')">+ 新建售后/维保工单</button>
        @elseif($activeTab === 'settlements')
            <button type="button" class="btn btn-secondary" onclick="openMerchantModal('modal-create-invoice')">+ 登记增值税发票</button>
            <form method="POST" action="{{ route('shop.merchant.settlements.store') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-primary">一键生成本期结算单</button>
            </form>
        @elseif($activeTab === 'settings')
            <button type="button" class="btn btn-secondary" onclick="openMerchantModal('modal-create-shop')">+ 开设新分店</button>
            <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-member')">+ 授权店铺成员</button>
        @else
            <a href="{{ route('shop.merchant.orders', ['create' => 1]) }}" class="btn btn-secondary">+ 登记履约订单</a>
            <a href="{{ route('shop.merchant.products', ['create' => 1]) }}" class="btn btn-primary">+ 发布新商品</a>
        @endif
    </div>
</div>

{{-- ==================== TAB 1: 概览看板 (Overview) ==================== --}}
@if($activeTab === 'overview')
    <div class="kpi-grid">
        <div class="kpi-card" style="border-top: 3px solid #2563EB;">
            <div class="kpi-label">
                <span>累计销售总额 (GMV)</span>
                <span class="badge badge-blue">出单总额</span>
            </div>
            <div class="kpi-value">¥{{ $metrics['gmv']['value'] ?? '0.00' }}</div>
            <div class="kpi-basis">名下售卖与租赁履约订单历史毛额合计</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #059669;">
            <div class="kpi-label">
                <span>实际净销售额</span>
                <span class="badge badge-green">已扣退款</span>
            </div>
            <div class="kpi-value">¥{{ $metrics['net_sales']['value'] ?? '0.00' }}</div>
            <div class="kpi-basis">剔除撤单、含定损补偿与逾期费</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #D97706;">
            <div class="kpi-label">
                <span>履约中在押押金池</span>
                <span class="badge badge-amber">监管专户</span>
            </div>
            <div class="kpi-value">¥{{ $metrics['deposit_in_custody']['value'] ?? '0.00' }}</div>
            <div class="kpi-basis">在租与验机期已冻结押金监管总额</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #4F46E5;">
            <div class="kpi-label">
                <span>待归集结算净额</span>
                <span class="badge badge-blue">T+1 对账</span>
            </div>
            <div class="kpi-value">¥{{ number_format(($metrics['settlement_pending_cents']['value'] ?? 0) / 100, 2) }}</div>
            <div class="kpi-basis">{{ $metrics['settlement_pending_cents']['basis'] ?? '' }}</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">
                <span>在架商品 / 待审核</span>
                <span class="badge badge-green">SPU/SKU</span>
            </div>
            <div class="kpi-value">
                {{ $metrics['on_shelf_products']['value'] ?? 0 }}
                <span style="font-size: 16px; color: var(--text-muted); font-weight: 500;">/ {{ $metrics['pending_review_products']['value'] ?? 0 }}</span>
            </div>
            <div class="kpi-basis">已过审上架 / 待审核及草稿</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">
                <span>可售库存 / 可租机身</span>
                <span class="badge badge-blue">双轨隔离</span>
            </div>
            <div class="kpi-value">
                {{ $metrics['sellable_stock_total']['value'] ?? 0 }}
                <span style="font-size: 16px; color: var(--text-muted); font-weight: 500;">/ {{ $metrics['rentable_stock_total']['value'] ?? 0 }} 台</span>
            </div>
            <div class="kpi-basis">维保检修锁定 {{ $metrics['maintenance_stock_total']['value'] ?? 0 }} 台</div>
        </div>
    </div>

    {{-- L3 经营待办与预警直达穿透矩阵 --}}
    <div class="todo-grid">
        <a class="todo-card" href="{{ route('shop.merchant.orders', ['status' => 'pending_shipment']) }}">
            <div class="todo-title">待出库发货</div>
            <div class="todo-num">{{ $metrics['todo_pending_shipment']['value'] ?? 0 }}</div>
            <div class="todo-hint">绑定机身 SN 发货 <span>→</span></div>
        </a>
        <a class="todo-card" href="{{ route('shop.merchant.orders', ['status' => 'returned_inspecting']) }}">
            <div class="todo-title">待归还验机</div>
            <div class="todo-num">{{ $metrics['todo_pending_inspection']['value'] ?? 0 }}</div>
            <div class="todo-hint">定损与退押金核算 <span>→</span></div>
        </a>
        <a class="todo-card" href="{{ route('shop.merchant.orders', ['fulfillment_type' => 'rental']) }}">
            <div class="todo-title">72h 内租期届满</div>
            <div class="todo-num">{{ $metrics['todo_expiring_rentals']['value'] ?? 0 }}</div>
            <div class="todo-hint">跟进在租合同归还 <span>→</span></div>
        </a>
        <a class="todo-card" href="{{ route('shop.merchant.after-sales', ['status' => 'open']) }}">
            <div class="todo-title">待办售后与维保</div>
            <div class="todo-num">{{ $metrics['todo_open_tickets']['value'] ?? 0 }}</div>
            <div class="todo-hint">检修办结释放库存 <span>→</span></div>
        </a>
        <a class="todo-card" href="{{ route('shop.merchant.products', ['low_stock' => 1]) }}">
            <div class="todo-title">安全库存预警</div>
            <div class="todo-num" style="color: {{ ($metrics['low_stock_alerts']['value'] ?? 0) > 0 ? 'var(--danger)' : 'var(--text-primary)' }};">
                {{ $metrics['low_stock_alerts']['value'] ?? 0 }}
            </div>
            <div class="todo-hint">及时补货与盘点 <span>→</span></div>
        </a>
        <a class="todo-card" href="{{ route('shop.merchant.settlements') }}">
            <div class="todo-title">财务结算与发票</div>
            <div class="todo-num" style="font-size: 18px; line-height: 29px;">账单开票</div>
            <div class="todo-hint">进入对账中心 <span>→</span></div>
        </a>
    </div>

    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">最近更新商品与租赁资产</h2>
                <div class="card-subtitle">支持机器人整机售卖、整机租期阶梯方案与机甲配件真机适配管理</div>
            </div>
            <a href="{{ route('shop.merchant.products') }}" class="btn btn-secondary btn-sm">进入商品与租赁目录 →</a>
        </div>

        @if(count($products->items()) === 0)
            <div class="empty-box">
                <div class="empty-box-icon">🤖</div>
                <div class="empty-box-title">当前店铺暂未发布商品</div>
                <div class="empty-box-desc">您可以创建机器人整机售卖、整机租赁或机甲配件商品，并配置多规格 SKU 与阶梯租期。</div>
                <a href="{{ route('shop.merchant.products', ['create' => 1]) }}" class="btn btn-primary">+ 立即发布首件商品</a>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>商品名称 / 主编码</th>
                            <th>业务模式</th>
                            <th>基准价 / 押金</th>
                            <th>双轨库存状态</th>
                            <th>审核状态</th>
                            <th>架上状态</th>
                            <th style="text-align: right;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach(collect($products->items())->take(6) as $item)
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-primary);">{{ $item['name'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                        <code>{{ $item['sku'] }}</code> · {{ count($item['skus'] ?? []) }} 个规格 · {{ count($item['structured_plans'] ?? []) }} 套租期方案
                                    </div>
                                </td>
                                <td>
                                    @if($item['product_type'] === 'sale_robot')
                                        <span class="badge badge-blue">机器人整机售卖</span>
                                    @elseif($item['product_type'] === 'rental_robot')
                                        <span class="badge badge-amber">机器人整机租赁</span>
                                    @else
                                        <span class="badge badge-gray">机甲配件 / 外设</span>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 700;">¥{{ $item['price_yuan'] }}</div>
                                    @if($item['deposit_cents'] > 0)
                                        <div style="font-size: 11.5px; color: var(--text-muted);">押金 ¥{{ $item['deposit_yuan'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 500;">可售 {{ $item['sellable_stock'] }} · 可租 {{ $item['rentable_stock'] }}</div>
                                    @if(!empty($item['is_low_stock']))
                                        <span class="badge badge-red" style="margin-top: 3px;">低于安全库存</span>
                                    @endif
                                </td>
                                <td>
                                    @if($item['review_status'] === 'approved')
                                        <span class="badge badge-green"><span class="badge-dot"></span>审核通过</span>
                                    @elseif($item['review_status'] === 'in_review')
                                        <span class="badge badge-amber"><span class="badge-dot"></span>待平台复核</span>
                                    @elseif($item['review_status'] === 'rejected')
                                        <span class="badge badge-red"><span class="badge-dot"></span>已驳回</span>
                                    @else
                                        <span class="badge badge-gray"><span class="badge-dot"></span>草稿</span>
                                    @endif
                                </td>
                                <td>
                                    @if($item['shelf_status'] === 'on_shelf')
                                        <span class="badge badge-green">在架销售</span>
                                    @else
                                        <span class="badge badge-gray">已下架</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('shop.merchant.products.show', $item['product_id']) }}" class="btn btn-soft btn-sm">工作台配置 →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

{{-- ==================== TAB 2: 商品与租赁 (Products) ==================== --}}
@if($activeTab === 'products')
    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">商品与租赁目录</h2>
                <div class="card-subtitle">支持草稿编撰 → 提交平台审核 → 过审上架 / 随时下架（下架不影响在途及在租订单履约）</div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="openMerchantModal('modal-create-product')">+ 发布新商品</button>
        </div>

        <form method="GET" action="{{ route('shop.merchant.products') }}" class="filter-bar">
            <select name="product_type">
                <option value="">全部业务类型</option>
                <option value="sale_robot" {{ request('product_type') === 'sale_robot' ? 'selected' : '' }}>机器人整机售卖</option>
                <option value="rental_robot" {{ request('product_type') === 'rental_robot' ? 'selected' : '' }}>机器人整机租赁</option>
                <option value="accessory" {{ request('product_type') === 'accessory' ? 'selected' : '' }}>机甲配件 / 外设</option>
            </select>
            <select name="review_status">
                <option value="">全部审核状态</option>
                <option value="draft" {{ request('review_status') === 'draft' ? 'selected' : '' }}>草稿待提审</option>
                <option value="in_review" {{ request('review_status') === 'in_review' ? 'selected' : '' }}>待平台复核</option>
                <option value="approved" {{ request('review_status') === 'approved' ? 'selected' : '' }}>审核已通过</option>
                <option value="rejected" {{ request('review_status') === 'rejected' ? 'selected' : '' }}>审核已驳回</option>
            </select>
            <select name="shelf_status">
                <option value="">全部架上状态</option>
                <option value="on_shelf" {{ request('shelf_status') === 'on_shelf' ? 'selected' : '' }}>在架</option>
                <option value="off_shelf" {{ request('shelf_status') === 'off_shelf' ? 'selected' : '' }}>已下架</option>
            </select>
            <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="搜索商品名称或 SKU 编码..." style="min-width: 220px;">
            <button type="submit" class="btn btn-secondary btn-sm">查询过滤</button>
            @if(request()->anyFilled(['product_type', 'review_status', 'shelf_status', 'keyword', 'low_stock']))
                <a href="{{ route('shop.merchant.products') }}" class="btn btn-secondary btn-sm">清空条件</a>
            @endif
        </form>

        @if(count($products->items()) === 0)
            <div class="empty-box">
                <div class="empty-box-icon">📦</div>
                <div class="empty-box-title">未找到符合条件的商品</div>
                <div class="empty-box-desc">可调整上方筛选条件，或点击右上角「+ 发布新商品」创建商品档案。</div>
                <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-product')">+ 发布新商品</button>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>商品档案 / 规格矩阵</th>
                            <th>业务模式 / 真机适配</th>
                            <th>售价 / 租赁押金</th>
                            <th>双轨库存 (售/租/锁/修)</th>
                            <th>审核状态</th>
                            <th>上下架</th>
                            <th style="text-align: right;">闭环流转与深度配置</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products->items() as $item)
                            <tr>
                                <td>
                                    <div style="font-weight: 600; font-size: 14px;">
                                        <a href="{{ route('shop.merchant.products.show', $item['product_id']) }}" style="color: var(--text-primary);">
                                            {{ $item['name'] }}
                                        </a>
                                    </div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 3px;">
                                        <code>{{ $item['sku'] }}</code> · 规格 {{ count($item['skus'] ?? []) }} 项 · 租期方案 {{ count($item['structured_plans'] ?? []) }} 套 · 乐观锁 v{{ $item['version'] }}
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        @if($item['product_type'] === 'sale_robot')
                                            <span class="badge badge-blue">机器人整机售卖</span>
                                        @elseif($item['product_type'] === 'rental_robot')
                                            <span class="badge badge-amber">机器人整机租赁</span>
                                        @else
                                            <span class="badge badge-gray">机甲配件</span>
                                        @endif
                                    </div>
                                    @if(!empty($item['compatible_models']))
                                        <div style="font-size: 11.5px; color: var(--text-secondary); margin-top: 4px;">
                                            适配：<code>{{ implode('</code> <code>', $item['compatible_models']) }}</code>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 700; font-size: 14px;">¥{{ $item['price_yuan'] }}</div>
                                    @if($item['deposit_cents'] > 0)
                                        <div style="font-size: 12px; color: var(--text-muted);">押金 ¥{{ $item['deposit_yuan'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 600;">可售 {{ $item['sellable_stock'] }} · 可租 {{ $item['rentable_stock'] }}</div>
                                    <div style="font-size: 11.5px; color: var(--text-muted);">在租锁 {{ $item['rented_locked_qty'] }} · 维保修 {{ $item['maintenance_qty'] }}</div>
                                </td>
                                <td>
                                    @if($item['review_status'] === 'approved')
                                        <span class="badge badge-green"><span class="badge-dot"></span>审核通过</span>
                                    @elseif($item['review_status'] === 'in_review')
                                        <span class="badge badge-amber"><span class="badge-dot"></span>待平台复核</span>
                                    @elseif($item['review_status'] === 'rejected')
                                        <span class="badge badge-red"><span class="badge-dot"></span>已驳回</span>
                                        @if(!empty($item['reject_reason']))
                                            <div style="font-size: 11px; color: var(--danger); margin-top: 3px; max-width: 160px;">原因：{{ $item['reject_reason'] }}</div>
                                        @endif
                                    @else
                                        <span class="badge badge-gray"><span class="badge-dot"></span>草稿</span>
                                    @endif
                                </td>
                                <td>
                                    @if($item['shelf_status'] === 'on_shelf')
                                        <span class="badge badge-green">已上架</span>
                                    @else
                                        <span class="badge badge-gray">已下架</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                        <a href="{{ route('shop.merchant.products.show', $item['product_id']) }}" class="btn btn-soft btn-sm">配置 SKU / 租期</a>

                                        @if(in_array($item['review_status'], ['draft', 'rejected'], true))
                                            <form method="POST" action="{{ route('shop.merchant.products.submit-review', $item['product_id']) }}" style="margin:0;">
                                                @csrf
                                                <input type="hidden" name="auto_approve" value="1">
                                                <button type="submit" class="btn btn-secondary btn-sm">提交审核</button>
                                            </form>
                                        @endif

                                        @if($item['review_status'] === 'approved' && $item['shelf_status'] !== 'on_shelf')
                                            <form method="POST" action="{{ route('shop.merchant.products.on-shelf', $item['product_id']) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">立即上架</button>
                                            </form>
                                        @endif

                                        @if($item['shelf_status'] === 'on_shelf')
                                            <form method="POST" action="{{ route('shop.merchant.products.off-shelf', $item['product_id']) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-danger btn-sm">下架</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 弹窗：发布新商品 --}}
    <div id="modal-create-product" class="modal-backdrop {{ request()->boolean('create') ? 'is-open' : '' }}" onclick="if(event.target===this)closeMerchantModal('modal-create-product')">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">发布机器人整机售卖 / 整机租赁 / 机甲配件</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-product')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.products.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>商品名称</label>
                            <input type="text" name="name" required placeholder="例如：Unitree G1 教育版人形机器人 / 钛合金战术护甲">
                        </div>
                        <div class="field">
                            <label>商品主 SKU 编码（留空自动生成）</label>
                            <input type="text" name="sku" placeholder="例如：MCH-G1-EDU-01">
                        </div>
                        <div class="field">
                            <label>商品业务类型</label>
                            <select name="product_type" required>
                                <option value="sale_robot">机器人整机售卖</option>
                                <option value="rental_robot">机器人整机租赁</option>
                                <option value="accessory">机甲配件 / 外设皮肤</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>交付履约模式</label>
                            <select name="fulfillment_type">
                                <option value="sale">售卖买断履约</option>
                                <option value="rental">租期循环履约（含押金与验机）</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>基准售价 / 基准日租（元）</label>
                            <input type="number" step="0.01" min="0" name="price_yuan" required value="9900.00">
                            <div class="field-hint">系统落库自动转为整数分，杜绝浮点精度误差</div>
                        </div>
                        <div class="field">
                            <label>标准租赁押金（元，仅租赁商品需填）</label>
                            <input type="number" step="0.01" min="0" name="deposit_yuan" value="0.00">
                        </div>
                        <div class="field">
                            <label>初始可售库存（售卖轨）</label>
                            <input type="number" min="0" name="sellable_stock" value="10">
                        </div>
                        <div class="field">
                            <label>初始可租库存（租赁轨）</label>
                            <input type="number" min="0" name="rentable_stock" value="5">
                        </div>
                        <div class="field">
                            <label>适配真机编码（逗号分隔，严禁绑定 L3 概念展示机型）</label>
                            <input type="text" name="compatible_models_raw" placeholder="例如：unitree_g1, unitree_go2">
                            @if(count($deviceModels) > 0)
                                <div class="field-hint">
                                    平台已认证真机：
                                    @foreach($deviceModels as $dm)
                                        <code>{{ $dm->code }}</code>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="field">
                            <label>快捷租期阶梯价（日租 / 周租 / 月租 元，选填）</label>
                            <div class="form-grid-3">
                                <input type="number" step="0.01" min="0" name="daily_rent_yuan" placeholder="日租(元)">
                                <input type="number" step="0.01" min="0" name="weekly_rent_yuan" placeholder="周租(元)">
                                <input type="number" step="0.01" min="0" name="monthly_rent_yuan" placeholder="月租(元)">
                            </div>
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>核心卖点与履约服务说明</label>
                        <input type="text" name="short_description" placeholder="一句话说明包含配件、驻场调试或质保服务范围">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-product')">取消</button>
                    <button type="submit" class="btn btn-primary">创建商品并进入详情工作台 →</button>
                </div>
            </form>
        </div>
    </div>
@endif

{{-- ==================== TAB 3: 双轨库存与多仓 (Inventory) ==================== --}}
@if($activeTab === 'inventory')
    {{-- L3 双轨库存主表 --}}
    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">双轨库存中心（售卖可售轨 × 租赁可租轨 × 维保检修池）</h2>
                <div class="card-subtitle">采用乐观锁版本号防并发超卖超租；支持一键将机身转入维保锁定，检修合格后释放回可租池</div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openMerchantModal('modal-create-warehouse')">+ 新增履约仓库</button>
        </div>

        @if(count($products->items()) === 0)
            <div class="empty-box">
                <div class="empty-box-icon">🏭</div>
                <div class="empty-box-title">暂无可调度的商品库存</div>
                <div class="empty-box-desc">请先在「商品与租赁」中创建商品档案。</div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>商品名称 / SKU</th>
                            <th>售卖可售库存</th>
                            <th>租赁可租库存</th>
                            <th>在租占用锁定</th>
                            <th>维保检修锁定</th>
                            <th>安全预警阈值</th>
                            <th style="text-align: right;">库存盘点与维保调度</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products->items() as $item)
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">{{ $item['name'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);"><code>{{ $item['sku'] }}</code> · 乐观锁 v{{ $item['version'] }}</div>
                                </td>
                                <td>
                                    <span style="font-size: 15px; font-weight: 700;">{{ $item['sellable_stock'] }}</span>
                                    <span style="font-size: 12px; color: var(--text-muted);">台/件</span>
                                </td>
                                <td>
                                    <span style="font-size: 15px; font-weight: 700; color: var(--brand-blue);">{{ $item['rentable_stock'] }}</span>
                                    <span style="font-size: 12px; color: var(--text-muted);">台</span>
                                </td>
                                <td>
                                    <span class="badge {{ $item['rented_locked_qty'] > 0 ? 'badge-amber' : 'badge-gray' }}">
                                        在租 {{ $item['rented_locked_qty'] }} 台
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $item['maintenance_qty'] > 0 ? 'badge-red' : 'badge-gray' }}">
                                        维保 {{ $item['maintenance_qty'] }} 台
                                    </span>
                                </td>
                                <td>
                                    <span>≤ {{ $item['safety_stock'] }} 台预警</span>
                                    @if(!empty($item['is_low_stock']))
                                        <span class="badge badge-red" style="margin-left: 4px;">低库存</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end;">
                                        <button
                                            type="button"
                                            class="btn btn-secondary btn-sm"
                                            onclick="openStocktakeModal({{ $item['product_id'] }}, '{{ addslashes($item['name']) }}', {{ $item['version'] }}, {{ $item['sellable_stock'] }}, {{ $item['rentable_stock'] }}, {{ $item['rented_locked_qty'] }}, {{ $item['maintenance_qty'] }}, {{ $item['safety_stock'] }})"
                                        >盘点调库</button>

                                        <form method="POST" action="{{ route('shop.merchant.inventory.adjust', $item['product_id']) }}" style="margin:0;">
                                            @csrf
                                            <input type="hidden" name="action_type" value="maintenance_lock">
                                            <input type="hidden" name="qty" value="1">
                                            <input type="hidden" name="reason" value="例行检修转入维保锁定">
                                            <button type="submit" class="btn btn-soft btn-sm" title="可租库存 -1，维保锁定 +1">转维保 1 台</button>
                                        </form>

                                        @if($item['maintenance_qty'] > 0)
                                            <form method="POST" action="{{ route('shop.merchant.inventory.adjust', $item['product_id']) }}" style="margin:0;">
                                                @csrf
                                                <input type="hidden" name="action_type" value="maintenance_release">
                                                <input type="hidden" name="qty" value="1">
                                                <input type="hidden" name="reason" value="维保完成释放回可租库存">
                                                <button type="submit" class="btn btn-primary btn-sm" title="维保锁定 -1，可租库存 +1">修复释放 1 台</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="form-grid">
        {{-- L2 多仓库履约节点 --}}
        <div class="card card-flush">
            <div class="card-header">
                <div>
                    <h2 class="card-title">多仓库履约与验机节点</h2>
                    <div class="card-subtitle">支持按区域配置发货仓与逆向归还验机仓</div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openMerchantModal('modal-create-warehouse')">+ 新增分仓</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>仓库编码 / 名称</th>
                            <th>区域与地址</th>
                            <th>仓管联络人</th>
                            <th>属性</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($warehouses as $wh)
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">{{ $wh['name'] }}</div>
                                    <code>{{ $wh['warehouse_code'] }}</code>
                                </td>
                                <td style="font-size: 12.5px; color: var(--text-secondary);">
                                    {{ $wh['region'] }} · {{ $wh['address'] ?: '标准履约仓' }}
                                </td>
                                <td style="font-size: 12.5px;">{{ $wh['contact_name'] }} {{ $wh['contact_phone'] }}</td>
                                <td>
                                    @if($wh['is_default'])
                                        <span class="badge badge-blue">默认主仓</span>
                                    @else
                                        <span class="badge badge-gray">区域分仓</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- L4 库存变动与锁定台账流水 --}}
        <div class="card card-flush">
            <div class="card-header">
                <div>
                    <h2 class="card-title">库存锁定与变动流水台账</h2>
                    <div class="card-subtitle">不可篡改记录每笔订单锁库、归还释放、重损转维保与盘点</div>
                </div>
            </div>
            @if(empty($inventoryLocks))
                <div class="empty-box" style="padding: 32px;">暂无库存变动流水</div>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>动作类型</th>
                                <th>变动</th>
                                <th>变动后快照 (售/租/锁/修)</th>
                                <th>原因说明</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(collect($inventoryLocks)->take(8) as $lk)
                                <tr>
                                    <td>
                                        <div style="font-weight: 600; font-size: 12.5px;">{{ $lockTypeMap[$lk['lock_type']] ?? $lk['lock_type'] }}</div>
                                        <div style="font-size: 11px; color: var(--text-muted);">商品 #{{ $lk['product_id'] }}</div>
                                    </td>
                                    <td>
                                        @if($lk['direction'] === 'lock')
                                            <span class="badge badge-amber">锁定 {{ $lk['qty'] }}</span>
                                        @elseif($lk['direction'] === 'release')
                                            <span class="badge badge-green">释放 {{ $lk['qty'] }}</span>
                                        @else
                                            <span class="badge badge-blue">调整 {{ $lk['qty'] }}</span>
                                        @endif
                                    </td>
                                    <td style="font-size: 12px; color: var(--text-secondary);">
                                        {{ $lk['snapshot']['sellable_stock'] ?? '-' }} / {{ $lk['snapshot']['rentable_stock'] ?? '-' }} / {{ $lk['snapshot']['rented_locked_qty'] ?? '-' }} / {{ $lk['snapshot']['maintenance_qty'] ?? '-' }}
                                    </td>
                                    <td style="font-size: 12px; color: var(--text-secondary);">{{ $lk['reason'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- 弹窗：盘点调库 --}}
    <div id="modal-stocktake" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-stocktake')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="stocktake-title">盘点校准双轨库存</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-stocktake')">✕</button>
            </div>
            <form id="stocktake-form" method="POST" action="">
                @csrf
                <input type="hidden" name="version" id="stocktake-version">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>售卖可售库存（台/件）</label>
                            <input type="number" min="0" name="sellable_stock" id="stocktake-sellable" required>
                        </div>
                        <div class="field">
                            <label>租赁可租库存（台）</label>
                            <input type="number" min="0" name="rentable_stock" id="stocktake-rentable" required>
                        </div>
                        <div class="field">
                            <label>在租占用锁定（台）</label>
                            <input type="number" min="0" name="rented_locked_qty" id="stocktake-locked" required>
                        </div>
                        <div class="field">
                            <label>维保检修锁定（台）</label>
                            <input type="number" min="0" name="maintenance_qty" id="stocktake-maint" required>
                        </div>
                    </div>
                    <div class="field">
                        <label>安全库存预警阈值（台）</label>
                        <input type="number" min="0" name="safety_stock" id="stocktake-safety" required>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>盘点调整原因备注</label>
                        <input type="text" name="reason" value="月度实物盘点校准" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-stocktake')">取消</button>
                    <button type="submit" class="btn btn-primary">确认更新库存</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗：新增仓库 --}}
    <div id="modal-create-warehouse" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-create-warehouse')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">新增履约与验机仓库节点</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-warehouse')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.warehouses.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>仓库名称</label>
                        <input type="text" name="name" required placeholder="例如：深圳南山机甲备件与验机中心仓">
                    </div>
                    <div class="field">
                        <label>所属大区 / 城市</label>
                        <input type="text" name="region" placeholder="例如：华南大区 · 深圳市">
                    </div>
                    <div class="field">
                        <label>详细收发货与逆向归还地址</label>
                        <input type="text" name="address" placeholder="用于生成客户租赁归还寄回地址">
                    </div>
                    <div class="form-grid">
                        <div class="field" style="margin-bottom:0;">
                            <label>仓管负责人</label>
                            <input type="text" name="contact_name" placeholder="姓名">
                        </div>
                        <div class="field" style="margin-bottom:0;">
                            <label>联络手机</label>
                            <input type="text" name="contact_phone" placeholder="手机号">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-warehouse')">取消</button>
                    <button type="submit" class="btn btn-primary">保存仓库节点</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openStocktakeModal(productId, name, version, sellable, rentable, locked, maint, safety) {
            document.getElementById('stocktake-title').textContent = '盘点调库 · ' + name;
            document.getElementById('stocktake-form').action = '/merchant/inventory/' + productId + '/adjust';
            document.getElementById('stocktake-version').value = version;
            document.getElementById('stocktake-sellable').value = sellable;
            document.getElementById('stocktake-rentable').value = rentable;
            document.getElementById('stocktake-locked').value = locked;
            document.getElementById('stocktake-maint').value = maint;
            document.getElementById('stocktake-safety').value = safety;
            openMerchantModal('modal-stocktake');
        }
    </script>
@endif

{{-- ==================== TAB 4: 订单与租赁履约 (Orders) ==================== --}}
@if($activeTab === 'orders')
    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">订单与租赁履约全状态机</h2>
                <div class="card-subtitle">
                    标准闭环：① 下单锁库存 & 签电子合同 → ② 出库绑机身 SN → ③ 在租履约 → ④ 归还验机定损（重损自动转维保工单） → ⑤ 完结入待结算池（支持未发货前安全取消与原路退库）
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="openMerchantModal('modal-create-order')">+ 登记履约订单</button>
        </div>

        {{-- 订单与资金核心指标网格 --}}
        <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); padding: 16px 20px 0; margin-bottom: 0;">
            <div class="kpi-card" style="padding: 12px 14px; border-top: 2px solid #2563EB;">
                <div class="kpi-label" style="font-size: 11.5px;">累计销售 (GMV)</div>
                <div class="kpi-value" style="font-size: 18px; color: #2563EB;">¥{{ $metrics['gmv']['value'] ?? '0.00' }}</div>
                <div class="kpi-basis" style="font-size: 10.5px;">历史出单总额合计</div>
            </div>
            <div class="kpi-card" style="padding: 12px 14px; border-top: 2px solid #059669;">
                <div class="kpi-label" style="font-size: 11.5px;">实际净销售额</div>
                <div class="kpi-value" style="font-size: 18px; color: #059669;">¥{{ $metrics['net_sales']['value'] ?? '0.00' }}</div>
                <div class="kpi-basis" style="font-size: 10.5px;">扣除取消与退款金额</div>
            </div>
            <div class="kpi-card" style="padding: 12px 14px; border-top: 2px solid #D97706;">
                <div class="kpi-label" style="font-size: 11.5px;">履约中在押押金池</div>
                <div class="kpi-value" style="font-size: 18px; color: #D97706;">¥{{ $metrics['deposit_in_custody']['value'] ?? '0.00' }}</div>
                <div class="kpi-basis" style="font-size: 10.5px;">在租履约监管专户</div>
            </div>
            <div class="kpi-card" style="padding: 12px 14px; border-top: 2px solid #4F46E5;">
                <div class="kpi-label" style="font-size: 11.5px;">订单履约汇总</div>
                <div class="kpi-value" style="font-size: 18px; color: #4F46E5;">{{ $metrics['orders_total']['value'] ?? 0 }} <span style="font-size: 12px; color: #DC2626; font-weight: 500;">/ {{ $metrics['cancelled_orders']['value'] ?? 0 }} 取消</span></div>
                <div class="kpi-basis" style="font-size: 10.5px;">已完结 {{ $metrics['completed_orders']['value'] ?? 0 }} 笔</div>
            </div>
        </div>

        <form method="GET" action="{{ route('shop.merchant.orders') }}" class="filter-bar">
            <select name="fulfillment_type">
                <option value="">全部履约模式</option>
                <option value="rental" {{ request('fulfillment_type') === 'rental' ? 'selected' : '' }}>机器人租赁履约单</option>
                <option value="sale" {{ request('fulfillment_type') === 'sale' ? 'selected' : '' }}>整机/配件售卖单</option>
            </select>
            <select name="status">
                <option value="">全部履约状态</option>
                <option value="pending_shipment" {{ request('status') === 'pending_shipment' ? 'selected' : '' }}>待出库发货</option>
                <option value="shipped" {{ request('status') === 'shipped' ? 'selected' : '' }}>已发货在途</option>
                <option value="in_use" {{ request('status') === 'in_use' ? 'selected' : '' }}>在租使用中</option>
                <option value="returned_inspecting" {{ request('status') === 'returned_inspecting' ? 'selected' : '' }}>归还待验机</option>
                <option value="inspected" {{ request('status') === 'inspected' ? 'selected' : '' }}>已验机定损</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>履约已完结</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>已取消/撤单</option>
            </select>
            <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="搜索订单号 / 客户名称 / 机身序列号 SN..." style="min-width: 240px;">
            <button type="submit" class="btn btn-secondary btn-sm">查询订单</button>
            @if(request()->anyFilled(['fulfillment_type', 'status', 'keyword']))
                <a href="{{ route('shop.merchant.orders') }}" class="btn btn-secondary btn-sm">重置</a>
            @endif
        </form>

        @if(count($orders->items()) === 0)
            <div class="empty-box">
                <div class="empty-box-icon">📋</div>
                <div class="empty-box-title">当前暂无客户履约订单</div>
                <div class="empty-box-desc">您可以点击右上角「+ 登记履约订单」创建企业租赁或整机买断订单，系统将自动锁定库存并生成电子合同。</div>
                <button type="button" class="btn btn-primary" onclick="openMerchantModal('modal-create-order')">+ 登记首笔履约订单</button>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>订单号 / 客户信息</th>
                            <th>商品与规格数量</th>
                            <th>合同与机身序列号 SN</th>
                            <th>订单总额 / 押金定损核算</th>
                            <th>当前履约状态</th>
                            <th style="text-align: right;">状态机流转操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders->items() as $ord)
                            @php
                                $st = $statusMap[$ord['status']] ?? ['label' => $ord['status'], 'class' => 'badge-gray'];
                            @endphp
                            <tr>
                                <td>
                                    <div style="font-weight: 700; color: var(--text-primary);"><code>{{ $ord['order_no'] }}</code></div>
                                    <div style="font-size: 12.5px; color: var(--text-secondary); margin-top: 3px;">
                                        {{ $ord['buyer_name_masked'] }} · {{ $ord['buyer_phone_masked'] }}
                                    </div>
                                    @if(!empty($ord['shipping_address_masked']) && $ord['shipping_address_masked'] !== '—')
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            送货地址：{{ $ord['shipping_address_masked'] }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 600;">{{ $ord['product_name'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">
                                        <code>{{ $ord['sku_code'] }}</code> × {{ $ord['qty'] }} 台/件
                                    </div>
                                </td>
                                <td>
                                    @if($ord['fulfillment_type'] === 'rental')
                                        <span class="badge badge-amber">租期 {{ $ord['rental_days'] }} 天</span>
                                        <div style="font-size: 11.5px; color: var(--text-secondary); margin-top: 4px;">
                                            电子合同：<code>{{ $ord['contract_no'] }}</code>
                                        </div>
                                    @else
                                        <span class="badge badge-blue">售卖买断</span>
                                    @endif
                                    @if(!empty($ord['device_sn']))
                                        <div style="font-size: 12px; margin-top: 3px;">
                                            机身 SN：<code>{{ $ord['device_sn'] }}</code>
                                        </div>
                                    @endif
                                    @if(!empty($ord['tracking_no']))
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                            去程物流：{{ $ord['carrier'] }} {{ $ord['tracking_no'] }}
                                        </div>
                                    @endif
                                    @if(!empty($ord['return_tracking_no']))
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            归还物流：{{ $ord['return_carrier'] }} {{ $ord['return_tracking_no'] }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-weight: 700; font-size: 14px;">¥{{ $ord['total_amount_yuan'] }}</div>
                                    @if($ord['deposit_cents'] > 0)
                                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">
                                            押金 ¥{{ $ord['deposit_yuan'] }}
                                            @if($ord['damage_deduct_cents'] > 0)
                                                · <span style="color: var(--danger);">定损扣 ¥{{ $ord['damage_deduct_yuan'] }}</span>
                                            @endif
                                            @if($ord['status'] === 'inspected' || $ord['status'] === 'completed')
                                                · <span style="color: var(--success);">应退 ¥{{ $ord['refund_deposit_yuan'] }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $st['class'] }}">
                                        <span class="badge-dot"></span>{{ $st['label'] }}
                                    </span>
                                    @if($ord['settled'])
                                        <div style="font-size: 11px; color: var(--success); font-weight: 600; margin-top: 4px;">✓ 已归集至结算单</div>
                                    @endif
                                    @if($ord['status'] === 'cancelled')
                                        <div style="font-size: 11px; color: var(--danger); margin-top: 3px;">
                                            {{ $ord['cancel_party'] === 'buyer' ? '买家取消' : ($ord['cancel_party'] === 'merchant' ? '商家取消' : '平台撤单') }}：{{ $ord['cancel_reason'] ?: '协商一致' }}
                                        </div>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">
                                        @if($ord['status'] === 'pending_shipment')
                                            <button
                                                type="button"
                                                class="btn btn-primary btn-sm"
                                                onclick="openShipOrderModal({{ $ord['id'] }}, '{{ $ord['order_no'] }}', '{{ addslashes($ord['product_name']) }}')"
                                            >出库发货</button>
                                            <button
                                                type="button"
                                                class="btn btn-secondary btn-sm"
                                                onclick="openCancelOrderModal({{ $ord['id'] }}, '{{ $ord['order_no'] }}')"
                                                style="color: var(--danger);"
                                            >取消订单</button>
                                        @elseif($ord['status'] === 'in_use')
                                            <button
                                                type="button"
                                                class="btn btn-soft btn-sm"
                                                onclick="openReturnOrderModal({{ $ord['id'] }}, '{{ $ord['order_no'] }}', '{{ $ord['device_sn'] }}')"
                                            >登记归还</button>
                                        @elseif($ord['status'] === 'returned_inspecting')
                                            <button
                                                type="button"
                                                class="btn btn-primary btn-sm"
                                                onclick="openInspectOrderModal({{ $ord['id'] }}, '{{ $ord['order_no'] }}', '{{ $ord['device_sn'] }}', '{{ $ord['deposit_yuan'] }}')"
                                            >验机定损</button>
                                        @elseif(in_array($ord['status'], ['shipped', 'inspected'], true))
                                            <form method="POST" action="{{ route('shop.merchant.orders.complete', $ord['id']) }}" style="margin:0; display:inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">完结订单</button>
                                            </form>
                                        @endif

                                        <button
                                            type="button"
                                            class="btn btn-soft btn-sm"
                                            onclick="openMerchantOrderDetailModal({{ json_encode($ord) }})"
                                        >详情</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 弹窗 1：登记新订单 --}}
    <div id="modal-create-order" class="modal-backdrop {{ request()->boolean('create') ? 'is-open' : '' }}" onclick="if(event.target===this)closeMerchantModal('modal-create-order')">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">登记售卖订单 / 租赁履约订单</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-order')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.orders.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>选择履约商品</label>
                            <select name="product_id" required>
                                @foreach($products->items() as $p)
                                    <option value="{{ $p['product_id'] }}">
                                        #{{ $p['product_id'] }} {{ $p['name'] }}（可售:{{ $p['sellable_stock'] }} / 可租:{{ $p['rentable_stock'] }}）
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label>业务履约模式</label>
                            <select name="fulfillment_type" required>
                                <option value="rental">机器人租赁履约单（锁可租库存 + 签电子合同）</option>
                                <option value="sale">整机/配件买断售卖单（扣减可售库存）</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>客户姓名 / 承租企业名称</label>
                            <input type="text" name="buyer_name" required placeholder="例如：杭州具身智能赛训俱乐部">
                        </div>
                        <div class="field">
                            <label>客户联系电话</label>
                            <input type="text" name="buyer_phone" placeholder="13800138000">
                        </div>
                        <div class="field">
                            <label>订购/承租台数</label>
                            <input type="number" min="1" max="50" name="qty" required value="1">
                        </div>
                        <div class="field">
                            <label>租期天数（仅租赁单生效）</label>
                            <input type="number" min="1" max="365" name="rental_days" value="7">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>交付收货地址</label>
                        <input type="text" name="shipping_address" placeholder="客户收货与现场交付地址">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-order')">取消</button>
                    <button type="submit" class="btn btn-primary">创建订单并锁定库存</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 2：出库发货并绑定机身序列号 SN --}}
    <div id="modal-ship-order" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-ship-order')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="ship-order-title">出库发货并绑定机身 SN</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-ship-order')">✕</button>
            </div>
            <form id="ship-order-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>物流承运商</label>
                        <select name="carrier">
                            <option value="顺丰大件特快">顺丰大件特快 (SF-Express)</option>
                            <option value="跨越速运航空件">跨越速运航空件</option>
                            <option value="专车驻场工程师直送">专车驻场工程师直送</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>去程物流运单号</label>
                        <input type="text" name="tracking_no" required placeholder="例如：SF1092837465">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>出库真机序列号 SN（一机一码溯源）</label>
                        <input type="text" name="device_sn" placeholder="例如：SN-G1-2026-088">
                        <div class="field-hint">绑定后将全链路追踪该机身归还验机与售后维保记录</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-ship-order')">取消</button>
                    <button type="submit" class="btn btn-primary">确认出库发货</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 3：登记客户逆向归还 --}}
    <div id="modal-return-order" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-return-order')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="return-order-title">登记租赁设备归还</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-return-order')">✕</button>
            </div>
            <form id="return-order-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>逆向物流承运商</label>
                        <input type="text" name="return_carrier" value="顺丰逆向物流">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>归还物流运单号</label>
                        <input type="text" name="return_tracking_no" required placeholder="输入客户寄回的运单号">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-return-order')">取消</button>
                    <button type="submit" class="btn btn-primary">确认登记归还（进入验机环节）</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 4：归还验机与定损核算 --}}
    <div id="modal-inspect-order" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-inspect-order')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="inspect-order-title">归还验机与押金定损核算</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-inspect-order')">✕</button>
            </div>
            <form id="inspect-order-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>整机检测结论（联动双轨库存与维保工单）</label>
                        <select name="inspection_result" required>
                            <option value="passed">完好无损 — 释放锁定库存回补可租池</option>
                            <option value="minor_damage">轻微磨损 — 扣除定损费并释放回可租池</option>
                            <option value="major_damage">严重受损 — 转入维保锁定库存 + 自动建维保检修单</option>
                        </select>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label>定损赔偿扣款（元）</label>
                            <input type="number" step="0.01" min="0" name="damage_deduct_yuan" value="0.00">
                        </div>
                        <div class="field">
                            <label>逾期归还滞纳费（元）</label>
                            <input type="number" step="0.01" min="0" name="overdue_fee_yuan" value="0.00">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>验机工程师检测报告备注</label>
                        <input type="text" name="inspection_notes" placeholder="例如：关节扭矩标定正常 / 左臂谐波减速器外壳磕碰需返修">
                        <div class="field-hint" id="inspect-deposit-hint">系统将从已冻结押金中自动扣除定损与逾期费并计算应退押金</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-inspect-order')">取消</button>
                    <button type="submit" class="btn btn-primary">提交验机结论并核算退押</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openShipOrderModal(id, orderNo, productName) {
            document.getElementById('ship-order-title').textContent = '出库发货 · ' + orderNo;
            document.getElementById('ship-order-form').action = '/merchant/orders/' + id + '/ship';
            openMerchantModal('modal-ship-order');
        }
        function openReturnOrderModal(id, orderNo, sn) {
            document.getElementById('return-order-title').textContent = '归还登记 · ' + orderNo + (sn ? ' (' + sn + ')' : '');
            document.getElementById('return-order-form').action = '/merchant/orders/' + id + '/return';
            openMerchantModal('modal-return-order');
        }
        function openInspectOrderModal(id, orderNo, sn, depositYuan) {
            document.getElementById('inspect-order-title').textContent = '归还验机定损 · ' + orderNo;
            document.getElementById('inspect-order-form').action = '/merchant/orders/' + id + '/inspect';
            document.getElementById('inspect-deposit-hint').textContent = '当前订单冻结押金 ¥' + depositYuan + '，提交后自动计算应退押金并联动库存。';
            openMerchantModal('modal-inspect-order');
        }
        function openCancelOrderModal(id, orderNo) {
            document.getElementById('cancel-order-title').textContent = '取消订单 · ' + orderNo;
            document.getElementById('cancel-order-form').action = '/merchant/orders/' + id + '/cancel';
            openMerchantModal('modal-cancel-order');
        }
        function openMerchantOrderDetailModal(order) {
            document.getElementById('merchant-order-detail-title').textContent = '订单 ' + order.order_no + ' 全生命周期详情';
            document.getElementById('m-detail-amount').textContent = '¥' + order.total_amount_yuan;
            document.getElementById('m-detail-deposit').textContent = order.deposit_cents > 0 ? ('冻结押金: ¥' + order.deposit_yuan + (order.damage_deduct_cents > 0 ? (' · 定损扣款: ¥' + order.damage_deduct_yuan) : '')) : '免押金';
            document.getElementById('m-detail-buyer').textContent = (order.buyer_name_masked || '客户') + ' (' + (order.buyer_phone_masked || '—') + ')';
            document.getElementById('m-detail-address').textContent = '地址: ' + (order.shipping_address_masked || '自提/电子交付');
            document.getElementById('m-detail-product').textContent = order.product_name + ' × ' + order.qty;
            document.getElementById('m-detail-device').textContent = order.device_sn ? ('机身 SN: ' + order.device_sn) : (order.fulfillment_type === 'rental' ? '待出库绑定 SN' : '标品发货');

            const cancelBox = document.getElementById('m-detail-cancel-box');
            if (order.status === 'cancelled') {
                cancelBox.style.display = 'block';
                const partyText = order.cancel_party === 'buyer' ? '买家取消' : (order.cancel_party === 'merchant' ? '商家取消' : '平台撤单');
                document.getElementById('m-detail-cancel-reason').textContent = partyText + '，原因：' + (order.cancel_reason || '协商一致');
            } else {
                cancelBox.style.display = 'none';
            }

            const timelineEl = document.getElementById('m-detail-timeline');
            timelineEl.innerHTML = '';
            if (Array.isArray(order.timeline) && order.timeline.length > 0) {
                order.timeline.forEach((node, idx) => {
                    const itemDiv = document.createElement('div');
                    itemDiv.style.position = 'relative';
                    itemDiv.style.marginBottom = (idx === order.timeline.length - 1) ? '0' : '14px';

                    let dotColor = '#94A3B8';
                    if (node.status === 'done') dotColor = '#059669';
                    else if (node.status === 'cancelled') dotColor = '#DC2626';
                    else if (node.status === 'wait') dotColor = '#D97706';

                    itemDiv.innerHTML = `
                        <div style="position: absolute; left: -22px; top: 3px; width: 10px; height: 10px; border-radius: 50%; background: ${dotColor}; box-shadow: 0 0 0 3px #FFF;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px;">
                            <span style="font-weight: 600; font-size: 13px; color: var(--text-primary);">${node.title}</span>
                            <span class="muted" style="font-size: 11px;">${node.time || '—'}</span>
                        </div>
                        <div class="muted" style="font-size: 12px; margin-top: 2px;">${node.desc || ''}</div>
                    `;
                    timelineEl.appendChild(itemDiv);
                });
            } else {
                timelineEl.innerHTML = '<span class="muted" style="font-size: 12px;">暂无节点记录</span>';
            }

            openMerchantModal('modal-merchant-order-detail');
        }
    </script>

    {{-- 弹窗 5：取消订单 --}}
    <div id="modal-cancel-order" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-cancel-order')">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title" id="cancel-order-title">取消履约订单</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-cancel-order')">✕</button>
            </div>
            <form id="cancel-order-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-warning" style="margin-bottom: 14px; font-size: 12.5px;">
                        ⚠️ <strong>预扣库存原路回退机制</strong>：取消订单后，系统将自动将该订单锁定的预扣库存（可售/可租）释放回可用库存池，并记入不可篡改的库存变动台账与审计流水。
                    </div>
                    <div class="field">
                        <label class="required">取消发起方</label>
                        <select name="party" required>
                            <option value="merchant">商家主动取消（缺货/与客户协商一致）</option>
                            <option value="buyer">买家要求取消（未出库前协商撤单）</option>
                            <option value="platform">平台调控撤单</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label class="required">取消原因详细说明</label>
                        <input type="text" name="reason" required placeholder="请填写详细取消原因（将记录于审计日志中）">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-cancel-order')">放弃</button>
                    <button type="submit" class="btn btn-danger">确认取消并原路释放库存</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 6：订单全生命周期详情与履约时间线 --}}
    <div id="modal-merchant-order-detail" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-merchant-order-detail')">
        <div class="modal-dialog" style="max-width: 680px;">
            <div class="modal-header">
                <h3 class="modal-title" id="merchant-order-detail-title">履约订单详情</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-merchant-order-detail')">✕</button>
            </div>
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px;">
                    <div style="background: var(--bg-hover); padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 3px;">订单金额 / 押金核算</div>
                        <div id="m-detail-amount" style="font-size: 18px; font-weight: 700; color: var(--text-primary);"></div>
                        <div id="m-detail-deposit" style="font-size: 11.5px; color: var(--text-secondary); margin-top: 2px;"></div>
                    </div>
                    <div style="background: var(--bg-hover); padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 3px;">客户信息 (安全脱敏)</div>
                        <div id="m-detail-buyer" style="font-weight: 600; color: var(--text-primary); font-size: 13.5px;"></div>
                        <div id="m-detail-address" style="font-size: 11.5px; color: var(--text-secondary); margin-top: 2px;"></div>
                    </div>
                    <div style="background: var(--bg-hover); padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-color);">
                        <div style="font-size: 11.5px; color: var(--text-muted); margin-bottom: 3px;">标的物与履约设备</div>
                        <div id="m-detail-product" style="font-weight: 600; color: #2563EB; font-size: 13.5px;"></div>
                        <div id="m-detail-device" style="font-size: 11.5px; color: var(--text-secondary); margin-top: 2px;"></div>
                    </div>
                </div>

                <div id="m-detail-cancel-box" class="alert alert-danger" style="display: none; margin-bottom: 16px;">
                    <strong>⚠️ 订单已取消</strong>
                    <div id="m-detail-cancel-reason" style="margin-top: 4px; font-size: 12.5px;"></div>
                </div>

                <div style="margin-top: 14px;">
                    <h4 style="margin: 0 0 12px; font-size: 13.5px; font-weight: 700; color: var(--text-primary);">全链路生命周期时间线</h4>
                    <div id="m-detail-timeline" style="border-left: 2px solid var(--border-color); margin-left: 10px; padding-left: 16px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-merchant-order-detail')">关闭</button>
            </div>
        </div>
    </div>
@endif

{{-- ==================== TAB 5: 售后与维保 (After-Sales & Maintenance) ==================== --}}
@if($activeTab === 'after_sales')
    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">售后与设备维保工单中心</h2>
                <div class="card-subtitle">支持退货退款、换货、客户报修与机身维保检修；工单办结后自动将维保锁定库存释放回可租池</div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" onclick="openMerchantModal('modal-create-ticket')">+ 新建售后/维保工单</button>
        </div>

        @if(count($afterSales->items()) === 0)
            <div class="empty-box">
                <div class="empty-box-icon">🛠️</div>
                <div class="empty-box-title">当前暂无售后或设备维保工单</div>
                <div class="empty-box-desc">当归还验机判定「严重受损」时系统会自动生成维保工单，您也可点击右上角手动登记。</div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>工单编号 / 类型</th>
                            <th>关联商品 / 订单 / 机身 SN</th>
                            <th>故障现象与检修结论</th>
                            <th>退款 / 赔偿核定</th>
                            <th>维保库存锁状态</th>
                            <th>工单状态</th>
                            <th style="text-align: right;">闭环办结</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($afterSales->items() as $tk)
                            @php
                                $tType = $ticketTypeMap[$tk['ticket_type']] ?? ['label' => $tk['ticket_type'], 'class' => 'badge-gray'];
                                $tStatus = $ticketStatusMap[$tk['status']] ?? ['label' => $tk['status'], 'class' => 'badge-gray'];
                            @endphp
                            <tr>
                                <td>
                                    <div style="font-weight: 700;"><code>{{ $tk['ticket_no'] }}</code></div>
                                    <span class="badge {{ $tType['class'] }}" style="margin-top: 4px;">{{ $tType['label'] }}</span>
                                </td>
                                <td>
                                    <div style="font-weight: 600;">{{ $tk['product_name'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                                        @if(!empty($tk['order_no'])) 订单: <code>{{ $tk['order_no'] }}</code> · @endif
                                        @if(!empty($tk['device_sn'])) SN: <code>{{ $tk['device_sn'] }}</code> @endif
                                    </div>
                                </td>
                                <td>
                                    <div style="color: var(--text-primary);">{{ $tk['reason'] }}</div>
                                    @if(!empty($tk['resolution_notes']))
                                        <div style="font-size: 12px; color: var(--success); margin-top: 4px; font-weight: 500;">
                                            ✓ 检修结论：{{ $tk['resolution_notes'] }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    <div>涉退 ¥{{ $tk['refund_yuan'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">定损/赔偿 ¥{{ $tk['compensation_yuan'] }}</div>
                                </td>
                                <td>
                                    @if($tk['locks_maintenance_stock'])
                                        <span class="badge badge-amber"><span class="badge-dot"></span>占用维保库存 1 台</span>
                                    @else
                                        <span class="badge badge-gray">已释放回可租池</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $tStatus['class'] }}">
                                        <span class="badge-dot"></span>{{ $tStatus['label'] }}
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    @if($tk['status'] !== 'resolved' && $tk['status'] !== 'rejected')
                                        <button
                                            type="button"
                                            class="btn btn-primary btn-sm"
                                            onclick="openResolveTicketModal({{ $tk['id'] }}, '{{ $tk['ticket_no'] }}', '{{ $tk['refund_yuan'] }}')"
                                        >办结并释放库存</button>
                                    @else
                                        <span class="badge badge-gray">已闭环</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 弹窗 1：新建售后/维保工单 --}}
    <div id="modal-create-ticket" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-create-ticket')">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">新建售后退换修 / 设备维保检修工单</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-ticket')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.after-sales.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>关联商品</label>
                            <select name="product_id" required>
                                @foreach($products->items() as $p)
                                    <option value="{{ $p['product_id'] }}">#{{ $p['product_id'] }} {{ $p['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label>工单业务类型</label>
                            <select name="ticket_type" required>
                                <option value="maintenance">设备维保检修单（自动占用 1 台维保库存）</option>
                                <option value="repair">客户报修工单</option>
                                <option value="exchange">换货申请单</option>
                                <option value="return_refund">退货退款单</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>机身序列号 SN</label>
                            <input type="text" name="device_sn" placeholder="例如：SN-G1-2026-088">
                        </div>
                        <div class="field">
                            <label>提单人 / 巡检工程师</label>
                            <input type="text" name="requester_name" placeholder="客户姓名或工程师姓名">
                        </div>
                        <div class="field">
                            <label>涉退金额（元）</label>
                            <input type="number" step="0.01" min="0" name="refund_yuan" value="0.00">
                        </div>
                        <div class="field">
                            <label>配件更换/赔偿金额（元）</label>
                            <input type="number" step="0.01" min="0" name="compensation_yuan" value="0.00">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>故障现象或售后原因描述</label>
                        <input type="text" name="reason" required placeholder="例如：右膝关节伺服电机异响，需更换谐波减速器并重新标定零点">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-ticket')">取消</button>
                    <button type="submit" class="btn btn-primary">提交工单</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 2：办结工单并释放维保库存 --}}
    <div id="modal-resolve-ticket" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-resolve-ticket')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="resolve-ticket-title">办结售后/维保工单</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-resolve-ticket')">✕</button>
            </div>
            <form id="resolve-ticket-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>处理结果状态</label>
                        <select name="status">
                            <option value="resolved">已检修合格 / 已妥善办结 (resolved)</option>
                            <option value="processing">转交原厂深度处理中 (processing)</option>
                            <option value="rejected">驳回申请 (rejected)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>最终核定退款金额（元）</label>
                        <input type="number" step="0.01" min="0" name="refund_yuan" id="resolve-ticket-refund" value="0.00">
                    </div>
                    <div class="field">
                        <label>检修与处理结论报告</label>
                        <input type="text" name="resolution_notes" required value="已完成关节模组检修与标定测试，整机状态合格">
                    </div>
                    <input type="hidden" name="release_maintenance_stock" value="1">
                    <div class="field-hint">办结后若该工单占用了维保锁定库存，将自动释放回可租可用库存池。</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-resolve-ticket')">取消</button>
                    <button type="submit" class="btn btn-primary">确认办结并释放库存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openResolveTicketModal(id, ticketNo, refundYuan) {
            document.getElementById('resolve-ticket-title').textContent = '办结工单 · ' + ticketNo;
            document.getElementById('resolve-ticket-form').action = '/merchant/after-sales/' + id + '/resolve';
            document.getElementById('resolve-ticket-refund').value = refundYuan;
            openMerchantModal('modal-resolve-ticket');
        }
    </script>
@endif

{{-- ==================== TAB 6: 结算与开票 (Settlements & Invoices) ==================== --}}
@if($activeTab === 'settlements')
    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">结算单对账中心（自动归集已完结订单与定损赔偿明细）</h2>
                <div class="card-subtitle">清分公式：应结净额 = 完结订单总额 + 验机定损赔偿收入 - 平台技术服务费(5%) - 售后退款抵扣</div>
            </div>
            <form method="POST" action="{{ route('shop.merchant.settlements.store') }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm">+ 一键归集生成结算单</button>
            </form>
        </div>

        @if(empty($settlements))
            <div class="empty-box">
                <div class="empty-box-icon">💳</div>
                <div class="empty-box-title">当前暂无已生成的结算单</div>
                <div class="empty-box-desc">当订单完结（completed）后，点击右上角「+ 一键归集生成结算单」即可自动汇总出账并生成行级对账单。</div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>结算单号 / 结算周期</th>
                            <th>归集订单数</th>
                            <th>订单毛额</th>
                            <th>定损赔偿收入 (+)</th>
                            <th>平台技术服务费 (-)</th>
                            <th>本期应结净额</th>
                            <th style="text-align: right;">对账状态与确认</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($settlements as $stl)
                            <tr>
                                <td>
                                    <div style="font-weight: 700;"><code>{{ $stl['settlement_no'] }}</code></div>
                                    <div style="font-size: 12px; color: var(--text-muted);">账期：{{ $stl['period_label'] }}</div>
                                </td>
                                <td><span class="badge badge-blue">{{ $stl['orders_count'] }} 笔完结单</span></td>
                                <td style="font-weight: 600;">¥{{ $stl['gross_amount_yuan'] }}</td>
                                <td style="color: var(--success); font-weight: 600;">+¥{{ $stl['damage_income_yuan'] }}</td>
                                <td style="color: var(--danger);">-¥{{ $stl['platform_fee_yuan'] }}</td>
                                <td style="font-weight: 700; font-size: 15px; color: var(--brand-blue);">¥{{ $stl['net_amount_yuan'] }}</td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                                        @if($stl['status'] === 'confirmed')
                                            <span class="badge badge-green"><span class="badge-dot"></span>已确认对账</span>
                                        @else
                                            <span class="badge badge-amber"><span class="badge-dot"></span>待商户确认</span>
                                            <form method="POST" action="{{ route('shop.merchant.settlements.confirm', $stl['id']) }}" style="margin:0;">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">确认账单无误</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if(!empty($stl['items']))
                                <tr style="background: #F8FAFC;">
                                    <td colspan="7" style="padding: 10px 20px;">
                                        <div style="font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px;">
                                            穿透对账行明细（{{ $stl['settlement_no'] }}）：
                                        </div>
                                        <div style="display: flex; flex-wrap: wrap; gap: 12px;">
                                            @foreach($stl['items'] as $line)
                                                <div style="font-size: 12px; background: #fff; border: 1px solid var(--border-subtle); padding: 6px 12px; border-radius: 6px;">
                                                    订单 <code>{{ $line['order_no'] }}</code>：毛额 ¥{{ $line['gross_yuan'] }} · 定损 +¥{{ $line['damage_yuan'] }} · 服务费 -¥{{ $line['fee_yuan'] }} → <strong>净额 ¥{{ $line['net_yuan'] }}</strong>
                                                </div>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">增值税发票工作台（专票 / 普票申请与开具回填）</h2>
                <div class="card-subtitle">支持关联合同或结算单申请开票，并回填税务发票代码与号码完成闭环</div>
            </div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openMerchantModal('modal-create-invoice')">+ 登记发票申请</button>
        </div>

        @if(empty($invoices))
            <div class="empty-box" style="padding: 36px;">
                <div class="empty-box-title">暂无增值税发票记录</div>
                <div class="empty-box-desc">点击右上角「+ 登记发票申请」可为企业客户登记专票或普票。</div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>发票申请单号 / 票种</th>
                            <th>购买方企业抬头 / 税号</th>
                            <th>价税合计 / 税率</th>
                            <th>税务发票代码与号码</th>
                            <th>开票状态</th>
                            <th style="text-align: right;">开具操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $inv)
                            <tr>
                                <td>
                                    <div style="font-weight: 700;"><code>{{ $inv['invoice_no'] }}</code></div>
                                    <span class="badge badge-blue" style="margin-top: 3px;">
                                        {{ $inv['invoice_type'] === 'vat_special' ? '增值税专用发票' : '增值税普通发票' }}
                                    </span>
                                </td>
                                <td>
                                    <div style="font-weight: 600;">{{ $inv['buyer_title'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">{{ $inv['buyer_tax_no'] ?: '个人 / 免税号' }}</div>
                                </td>
                                <td>
                                    <div style="font-weight: 700;">¥{{ $inv['amount_yuan'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">税率 {{ $inv['tax_rate'] }}</div>
                                </td>
                                <td>
                                    @if(!empty($inv['invoice_code_number']))
                                        <code>{{ $inv['invoice_code_number'] }}</code>
                                    @else
                                        <span style="color: var(--text-muted); font-size: 12px;">待回填发票号</span>
                                    @endif
                                </td>
                                <td>
                                    @if($inv['status'] === 'issued')
                                        <span class="badge badge-green"><span class="badge-dot"></span>已开具</span>
                                    @else
                                        <span class="badge badge-amber"><span class="badge-dot"></span>待开具</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    @if($inv['status'] !== 'issued')
                                        <button
                                            type="button"
                                            class="btn btn-primary btn-sm"
                                            onclick="openIssueInvoiceModal({{ $inv['id'] }}, '{{ $inv['invoice_no'] }}', '{{ addslashes($inv['buyer_title']) }}')"
                                        >回填票号并开具</button>
                                    @else
                                        <span style="font-size: 12px; color: var(--text-muted);">{{ $inv['issued_at'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 弹窗 1：登记发票 --}}
    <div id="modal-create-invoice" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-create-invoice')">
        <div class="modal-dialog">
            <div class="modal-header">
                <h3 class="modal-title">登记增值税发票申请</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-invoice')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.invoices.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>发票类型</label>
                            <select name="invoice_type">
                                <option value="vat_special">增值税专用发票</option>
                                <option value="vat_normal">增值税普通发票</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>关联结算单（选填）</label>
                            <select name="settlement_id">
                                <option value="">不限定特定结算单</option>
                                @foreach($settlements as $stl)
                                    <option value="{{ $stl['id'] }}">{{ $stl['settlement_no'] }} (¥{{ $stl['net_amount_yuan'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label>购买方企业名称 / 抬头</label>
                            <input type="text" name="buyer_title" required placeholder="例如：上海未来机甲科技有限公司">
                        </div>
                        <div class="field">
                            <label>纳税人识别号</label>
                            <input type="text" name="buyer_tax_no" placeholder="91310000MA1FL8XXXX">
                        </div>
                        <div class="field">
                            <label>价税合计金额（元）</label>
                            <input type="number" step="0.01" min="0.01" name="amount_yuan" required value="1000.00">
                        </div>
                        <div class="field">
                            <label>适用税率</label>
                            <select name="tax_rate">
                                <option value="6%">6%（现代技术与租赁服务）</option>
                                <option value="13%">13%（硬件整机与有形动产）</option>
                                <option value="3%">3%（小规模征收率）</option>
                            </select>
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>税务发票代码号码（若已开出可直接填写，留空则为待开具状态）</label>
                        <input type="text" name="invoice_code_number" placeholder="例如：031002600111-889201">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-invoice')">取消</button>
                    <button type="submit" class="btn btn-primary">保存发票记录</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 2：回填发票代码号码 --}}
    <div id="modal-issue-invoice" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-issue-invoice')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title" id="issue-invoice-title">开具增值税发票</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-issue-invoice')">✕</button>
            </div>
            <form id="issue-invoice-form" method="POST" action="">
                @csrf
                <div class="modal-body">
                    <div class="field" style="margin-bottom:0;">
                        <label>税务局数电发票号码 / 代码号码</label>
                        <input type="text" name="invoice_code_number" required placeholder="例如：033002600111-66889900">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-issue-invoice')">取消</button>
                    <button type="submit" class="btn btn-primary">确认开具完成</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openIssueInvoiceModal(id, invoiceNo, buyerTitle) {
            document.getElementById('issue-invoice-title').textContent = '回填票号 · ' + invoiceNo;
            document.getElementById('issue-invoice-form').action = '/merchant/invoices/' + id + '/issue';
            openMerchantModal('modal-issue-invoice');
        }
    </script>
@endif

{{-- ==================== TAB 7: 设置、成员 RBAC 与审计 (Settings) ==================== --}}
@if($activeTab === 'settings')
    <div class="form-grid">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">当前店铺档案与展示时区配置</h2>
                    <div class="card-subtitle">底层时间统一按 UTC 落库，前端界面按店铺配置时区自动转换展示</div>
                </div>
            </div>

            <form method="POST" action="{{ route('shop.merchant.shops.update-current') }}" style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--border-subtle);">
                @csrf
                <div class="field">
                    <label>当前经营店铺名称</label>
                    <input type="text" name="shop_name" required value="{{ $currentMerchantShop->shop_name }}">
                </div>
                <div class="field">
                    <label>店铺运营展示时区</label>
                    <select name="timezone">
                        @foreach(['Asia/Shanghai', 'UTC', 'Asia/Tokyo', 'Asia/Singapore', 'Europe/London', 'America/New_York'] as $tz)
                            <option value="{{ $tz }}" {{ ($currentMerchantShop->timezone ?? 'Asia/Shanghai') === $tz ? 'selected' : '' }}>{{ $tz }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label>店铺履约与服务承诺</label>
                    <input type="text" name="description" value="{{ $currentMerchantShop->description }}" placeholder="例如：24h 顺丰特快发机，含全套备用电池与出库标定报告">
                </div>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary btn-sm">保存当前店铺档案</button>
                </div>
            </form>

            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <h3 style="font-size: 13.5px; font-weight: 600; margin: 0;">名下多店铺矩阵（独立 <code>seller_id</code> 隔离）</h3>
                <button type="button" class="btn btn-secondary btn-sm" onclick="openMerchantModal('modal-create-shop')">+ 开设新分店</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>店铺名称</th>
                            <th>租户节点</th>
                            <th>时区</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($merchantShops as $s)
                            <tr>
                                <td style="font-weight: 600;">{{ $s->shop_name }}</td>
                                <td><code>#{{ $s->seller_id }}</code></td>
                                <td style="font-size: 12px;">{{ $s->timezone }}</td>
                                <td><span class="badge badge-green">营业中</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card card-flush">
            <div class="card-header">
                <div>
                    <h2 class="card-title">店铺成员与 RBAC 角色权限矩阵</h2>
                    <div class="card-subtitle">支持店主、管理员、商品运营、仓管履约、财务结算五级职责分离</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openMerchantModal('modal-create-member')">+ 授权店铺成员</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>成员姓名 / 账号邮箱</th>
                            <th>RBAC 角色</th>
                            <th>权限范围摘要</th>
                            <th>状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($members as $mb)
                            <tr>
                                <td>
                                    <div style="font-weight: 600;">{{ $mb['customer_name'] }}</div>
                                    <div style="font-size: 12px; color: var(--text-muted);">{{ $mb['customer_email'] }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-blue">{{ $mb['role_name'] }}</span>
                                </td>
                                <td style="font-size: 12px; color: var(--text-secondary);">
                                    @if(in_array('*', $mb['resolved_permissions'] ?? [], true))
                                        全部模块管理权限
                                    @else
                                        {{ count($mb['resolved_permissions'] ?? []) }} 项细分权限码
                                    @endif
                                </td>
                                <td><span class="badge badge-green">已激活</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-flush">
        <div class="card-header">
            <div>
                <h2 class="card-title">不可篡改操作审计日志</h2>
                <div class="card-subtitle">全量记录建品、SKU、租赁方案、提审、驳回、上下架、调库、发货、归还、验机、工单、结算、开票与成员授权</div>
            </div>
        </div>

        @if(count($auditLogs->items()) === 0)
            <div class="empty-box" style="padding: 32px;">暂无审计日志记录</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>审计动作编码</th>
                            <th>目标资源</th>
                            <th>操作人标识</th>
                            <th>关键变更载荷 (Payload)</th>
                            <th>发生时间 (UTC)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($auditLogs->items() as $log)
                            <tr>
                                <td><code>{{ $log->action }}</code></td>
                                <td style="font-size: 12.5px;">{{ $log->target_type }} <code>#{{ $log->target_id }}</code></td>
                                <td style="font-size: 12.5px;">{{ $log->operator }}</td>
                                <td style="font-size: 12px; color: var(--text-secondary); max-width: 440px; word-break: break-all;">
                                    <code>{{ \Illuminate\Support\Str::limit((string) $log->payload_json, 130) }}</code>
                                </td>
                                <td style="font-size: 12px; color: var(--text-secondary);">{{ $log->created_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- 弹窗 1：新建分店 --}}
    <div id="modal-create-shop" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-create-shop')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">开设新分店（分配独立 seller_id 租户）</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-shop')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.shops.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>分店名称</label>
                        <input type="text" name="shop_name" required placeholder="例如：华南赛事租赁履约分店">
                    </div>
                    <div class="field">
                        <label>展示时区</label>
                        <select name="timezone">
                            <option value="Asia/Shanghai">Asia/Shanghai (UTC+8)</option>
                            <option value="UTC">UTC</option>
                            <option value="Asia/Tokyo">Asia/Tokyo (UTC+9)</option>
                        </select>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>分店简介</label>
                        <input type="text" name="description" placeholder="负责华南赛区人形机器人短期租赁与驻场保障">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-shop')">取消</button>
                    <button type="submit" class="btn btn-primary">创建并切换至新分店</button>
                </div>
            </form>
        </div>
    </div>

    {{-- 弹窗 2：授权店铺成员 --}}
    <div id="modal-create-member" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-create-member')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">授权店铺成员与 RBAC 角色</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-create-member')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.members.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="field">
                        <label>成员注册邮箱</label>
                        <input type="email" name="email" required placeholder="输入平台已注册账号的邮箱">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>分配 RBAC 角色</label>
                        <select name="role" required>
                            @foreach($rolesConfig as $rCode => $rMeta)
                                <option value="{{ $rCode }}">{{ $rMeta['name'] }} ({{ $rCode }})</option>
                            @endforeach
                        </select>
                        <div class="field-hint">不同角色严格按权限码控制接口与写操作（如仓管不可上下架商品）</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-create-member')">取消</button>
                    <button type="submit" class="btn btn-primary">保存成员角色授权</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection
