@extends('shop::merchant.layouts.app')

@section('title', $product['name'] . ' · 商品与租赁配置工作台')

@section('content')
@php
    $periodLabelMap = [
        'day'     => '按天计费',
        'week'    => '按周计费',
        'month'   => '按月计费',
        'quarter' => '按季计费',
    ];
    $depositModeMap = [
        'full'          => '全额冻结押金',
        'ratio'         => '按比例押金',
        'credit_waiver' => '企业信用免押',
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
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <a href="{{ route('shop.merchant.products') }}" class="btn btn-secondary btn-sm">← 返回商品目录</a>
            <span class="stepper">
                <span class="step-pill {{ in_array($product['review_status'], ['draft', 'rejected'], true) ? 'current' : 'done' }}">① 档案与规格编撰</span>
                <span>→</span>
                <span class="step-pill {{ $product['review_status'] === 'in_review' ? 'current' : ($product['review_status'] === 'approved' ? 'done' : '') }}">② 平台合规审核</span>
                <span>→</span>
                <span class="step-pill {{ $product['shelf_status'] === 'on_shelf' ? 'done' : ($product['review_status'] === 'approved' ? 'current' : '') }}">③ 架上售卖/可租</span>
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <h1 class="page-title">{{ $product['name'] }}</h1>
            <code>{{ $product['sku'] }}</code>
            <span class="badge badge-blue">乐观锁 v{{ $product['version'] }}</span>
            @if($product['review_status'] === 'approved')
                <span class="badge badge-green"><span class="badge-dot"></span>审核已通过</span>
            @elseif($product['review_status'] === 'in_review')
                <span class="badge badge-amber"><span class="badge-dot"></span>待平台复核</span>
            @elseif($product['review_status'] === 'rejected')
                <span class="badge badge-red"><span class="badge-dot"></span>审核已驳回</span>
            @else
                <span class="badge badge-gray"><span class="badge-dot"></span>草稿待提审</span>
            @endif

            @if($product['shelf_status'] === 'on_shelf')
                <span class="badge badge-green">在架销售 / 可租</span>
            @else
                <span class="badge badge-gray">已下架</span>
            @endif
        </div>
    </div>

    <div class="page-actions">
        @if(in_array($product['review_status'], ['draft', 'rejected'], true))
            <form method="POST" action="{{ route('shop.merchant.products.submit-review', $product['product_id']) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-secondary">提交审核（进入复核队列）</button>
            </form>
            <form method="POST" action="{{ route('shop.merchant.products.submit-review', $product['product_id']) }}" style="margin:0;">
                @csrf
                <input type="hidden" name="auto_approve" value="1">
                <button type="submit" class="btn btn-primary">提交并快速过审</button>
            </form>
        @endif

        @if($product['review_status'] === 'in_review')
            <button type="button" class="btn btn-danger" onclick="openMerchantModal('modal-reject-review')">审核驳回</button>
            <form method="POST" action="{{ route('shop.merchant.products.review-decision', $product['product_id']) }}" style="margin:0;">
                @csrf
                <input type="hidden" name="decision" value="approve">
                <button type="submit" class="btn btn-primary">审核通过</button>
            </form>
        @endif

        @if($product['review_status'] === 'approved' && $product['shelf_status'] !== 'on_shelf')
            <form method="POST" action="{{ route('shop.merchant.products.on-shelf', $product['product_id']) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-primary">立即上架发售</button>
            </form>
        @endif

        @if($product['shelf_status'] === 'on_shelf')
            <form method="POST" action="{{ route('shop.merchant.products.off-shelf', $product['product_id']) }}" style="margin:0;">
                @csrf
                <button type="submit" class="btn btn-danger">下架商品</button>
            </form>
        @endif
    </div>
</div>

@if($product['review_status'] === 'rejected' && !empty($product['reject_reason']))
    <div class="flash flash-error">
        <span><strong>审核驳回原因：</strong>{{ $product['reject_reason'] }}（修改保存下方任意商品参数后将自动重置为草稿，可重新提交审核）</span>
    </div>
@endif

{{-- 顶部资产概览小卡 --}}
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label"><span>基准售价 / 日租金</span><span class="badge badge-blue">整数分存储</span></div>
        <div class="kpi-value">¥{{ $product['price_yuan'] }}</div>
        <div class="kpi-basis">日租基准 ¥{{ $product['daily_rent_yuan'] }}/天</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span>标准租赁押金</span><span class="badge badge-amber">押金风控</span></div>
        <div class="kpi-value">¥{{ $product['deposit_yuan'] }}</div>
        <div class="kpi-basis">最短起租 {{ $product['min_rent_days'] }} 天</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span>汇总可售 / 可租库存</span><span class="badge badge-green">多 SKU 汇总</span></div>
        <div class="kpi-value">{{ $product['sellable_stock'] }} <span style="font-size: 16px; color: var(--text-muted);">/ {{ $product['rentable_stock'] }} 台</span></div>
        <div class="kpi-basis">共 {{ count($product['skus'] ?? []) }} 个激活规格 SKU</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span>在租锁定 / 维保检修</span><span class="badge badge-gray">实时占用</span></div>
        <div class="kpi-value">{{ $product['rented_locked_qty'] }} <span style="font-size: 16px; color: var(--text-muted);">/ {{ $product['maintenance_qty'] }} 台</span></div>
        <div class="kpi-basis">安全库存预警线 ≤ {{ $product['safety_stock'] }} 台</div>
    </div>
</div>

{{-- 一、SPU 基础档案、技术参数与真机适配 --}}
<div class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">一、SPU 基础档案、技术参数与真机适配</h2>
            <div class="card-subtitle">支持维护机身自由度、续航、额定负载参数及 L1/L2 真机适配（严禁绑定 L3 概念展示机型）</div>
        </div>
        <span class="badge badge-gray">最近同步：{{ $product['updated_at_local'] ?? $product['updated_at'] }}</span>
    </div>

    <form method="POST" action="{{ route('shop.merchant.products.update', $product['product_id']) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $product['version'] }}">
        <div class="form-grid">
            <div class="field">
                <label>商品名称</label>
                <input type="text" name="name" required value="{{ $product['name'] }}">
            </div>
            <div class="field">
                <label>适配真机编码（逗号分隔）</label>
                <input type="text" name="compatible_models_raw" value="{{ implode(', ', $product['compatible_models'] ?? []) }}" placeholder="例如：unitree_g1, unitree_go2">
                @if(count($deviceModels) > 0)
                    <div class="field-hint">
                        可选真机：
                        @foreach($deviceModels as $dm)
                            <code>{{ $dm->code }}</code>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="field">
                <label>基准售价（元）</label>
                <input type="number" step="0.01" min="0" name="price_yuan" required value="{{ $product['price_yuan'] }}">
            </div>
            <div class="field">
                <label>基准日租金（元/天）</label>
                <input type="number" step="0.01" min="0" name="daily_rent_yuan" value="{{ $product['daily_rent_yuan'] }}">
            </div>
            <div class="field">
                <label>基准租赁押金（元）</label>
                <input type="number" step="0.01" min="0" name="deposit_yuan" value="{{ $product['deposit_yuan'] }}">
            </div>
            <div class="field">
                <label>最短起租天数 / 安全库存预警阈值</label>
                <div class="form-grid">
                    <input type="number" min="1" name="min_rent_days" value="{{ $product['min_rent_days'] }}" placeholder="最短租期(天)">
                    <input type="number" min="0" name="safety_stock" value="{{ $product['safety_stock'] }}" placeholder="预警阈值(台)">
                </div>
            </div>
            <div class="field">
                <label>核心技术参数（自由度 / 续航时间 / 额定负载）</label>
                <div class="form-grid-3">
                    <input type="text" name="param_dof" value="{{ $product['param_specs']['自由度/关节'] ?? '' }}" placeholder="如 23 DoF">
                    <input type="text" name="param_battery" value="{{ $product['param_specs']['续航时间'] ?? '' }}" placeholder="如 2.5 小时">
                    <input type="text" name="param_payload" value="{{ $product['param_specs']['额定负载'] ?? '' }}" placeholder="如 3.5 kg">
                </div>
            </div>
            <div class="field">
                <label>主图 / 高清媒体封面 URL</label>
                <input type="text" name="media_cover_url" value="{{ $product['media_items'][0]['url'] ?? '' }}" placeholder="https://example.com/mecha-cover.jpg">
            </div>
        </div>
        <div class="field">
            <label>商品一句话简述</label>
            <input type="text" name="short_description" value="{{ $product['short_description'] }}">
        </div>
        <div class="field">
            <label>详细技术规格与租赁服务条款</label>
            <textarea name="description" rows="2">{{ $product['description'] }}</textarea>
        </div>
        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">保存基础档案与技术参数</button>
        </div>
    </form>
</div>

{{-- 二、多规格 SKU 矩阵与双轨库存联动 --}}
<div class="card card-flush">
    <div class="card-header">
        <div>
            <h2 class="card-title">二、多规格 SKU 矩阵（SPU × SKU 双轨库存自动汇总）</h2>
            <div class="card-subtitle">新增或修改任意规格 SKU 后，系统自动将激活态 SKU 的可售与可租库存汇总回写至主商品</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openSkuModal(null, '', '', '', '{{ $product['price_yuan'] }}', '{{ $product['daily_rent_yuan'] }}', '{{ $product['deposit_yuan'] }}', 5, 3)">+ 新增规格 SKU</button>
    </div>

    @if(empty($product['skus']))
        <div class="empty-box" style="padding: 32px;">暂无规格 SKU，请点击右上角「+ 新增规格 SKU」添加。</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>SKU 编码</th>
                        <th>规格名称 / 规格属性</th>
                        <th>售价 / 日租 / 押金</th>
                        <th>可售库存</th>
                        <th>可租库存</th>
                        <th>锁定 / 维保</th>
                        <th>版本</th>
                        <th style="text-align: right;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($product['skus'] as $sku)
                        @php
                            $specStr = '';
                            if (!empty($sku['spec_json'])) {
                                foreach ($sku['spec_json'] as $k => $v) {
                                    $specStr .= $k . ':' . $v . ' ';
                                }
                            }
                        @endphp
                        <tr>
                            <td><code>{{ $sku['sku_code'] }}</code></td>
                            <td>
                                <div style="font-weight: 600;">{{ $sku['name'] }}</div>
                                @if($specStr !== '')
                                    <div style="font-size: 12px; color: var(--text-muted);">{{ trim($specStr) }}</div>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight: 600;">售 ¥{{ $sku['price_yuan'] }} · 租 ¥{{ $sku['daily_rent_yuan'] }}/天</div>
                                <div style="font-size: 12px; color: var(--text-muted);">押金 ¥{{ $sku['deposit_yuan'] }}</div>
                            </td>
                            <td style="font-weight: 700;">{{ $sku['sellable_stock'] }}</td>
                            <td style="font-weight: 700; color: var(--brand-blue);">{{ $sku['rentable_stock'] }}</td>
                            <td style="font-size: 12.5px; color: var(--text-secondary);">
                                锁 {{ $sku['locked_stock'] }} / 修 {{ $sku['maintenance_stock'] }}
                            </td>
                            <td><span class="badge badge-gray">v{{ $sku['version'] }}</span></td>
                            <td style="text-align: right;">
                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm"
                                    onclick="openSkuModal({{ $sku['id'] }}, '{{ addslashes($sku['name']) }}', '{{ $sku['sku_code'] }}', '{{ addslashes(trim($specStr)) }}', '{{ $sku['price_yuan'] }}', '{{ $sku['daily_rent_yuan'] }}', '{{ $sku['deposit_yuan'] }}', {{ $sku['sellable_stock'] }}, {{ $sku['rentable_stock'] }})"
                                >编辑规格</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- 三、租赁方案、租期阶梯价与押金规则 --}}
<div class="card card-flush">
    <div class="card-header">
        <div>
            <h2 class="card-title">三、租赁方案、租期阶梯价与押金风控规则</h2>
            <div class="card-subtitle">支持按天/周/月/季配置阶梯折扣、全额/比例/企业免押金规则、逾期日费与租转售买断价</div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="openMerchantModal('modal-add-rental-plan')">+ 新增租赁阶梯方案</button>
    </div>

    @if(empty($product['structured_plans']))
        <div class="empty-box" style="padding: 32px;">暂无租赁阶梯方案，点击右上角「+ 新增租赁阶梯方案」即可配置。</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>方案编码 / 方案名称</th>
                        <th>计费周期 / 允许租期</th>
                        <th>基准单价 / 阶梯折扣明细</th>
                        <th>押金风控模式 / 逾期日费 / 买断价</th>
                        <th>电子合同模板</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($product['structured_plans'] as $plan)
                        @php
                            $rule = $plan['deposit_rule'] ?? [];
                            $depYuan = number_format(((int) ($rule['deposit_cents'] ?? 0)) / 100, 2);
                            $overYuan = number_format(((int) ($rule['overdue_daily_cents'] ?? 0)) / 100, 2);
                            $buyoutYuan = number_format(((int) ($rule['buyout_price_cents'] ?? 0)) / 100, 2);
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight: 600;">{{ $plan['name'] }}</div>
                                <code>{{ $plan['plan_code'] }}</code>
                            </td>
                            <td>
                                <span class="badge badge-amber">{{ $periodLabelMap[$plan['period_unit']] ?? $plan['period_unit'] }}</span>
                                <span style="font-size: 12.5px; margin-left: 6px;">{{ $plan['min_days'] }} ~ {{ $plan['max_days'] }} 天</span>
                            </td>
                            <td>
                                <div style="font-weight: 700;">¥{{ $plan['base_price_yuan'] }}</div>
                                @if(!empty($plan['price_tiers']))
                                    <div style="font-size: 12px; color: var(--success); margin-top: 2px;">
                                        @foreach($plan['price_tiers'] as $tier)
                                            满 {{ $tier['min_days'] }} 天: ¥{{ number_format(($tier['daily_cents'] ?? 0) / 100, 2) }}/天 ·
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                <div style="font-weight: 500;">{{ $depositModeMap[$rule['mode'] ?? 'full'] ?? ($rule['mode'] ?? 'full') }} · 押金 ¥{{ $depYuan }}</div>
                                <div style="font-size: 12px; color: var(--text-muted);">逾期滞纳 ¥{{ $overYuan }}/天 · 买断尾款 ¥{{ $buyoutYuan }}</div>
                            </td>
                            <td><code>{{ $plan['contract_template_code'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- 四、商品库存变动与锁定台账 --}}
<div class="card card-flush">
    <div class="card-header">
        <div>
            <h2 class="card-title">四、商品库存锁定与变动流水</h2>
            <div class="card-subtitle">全链路追踪该商品每一笔售卖扣减、租赁占用、归还释放、维保锁定与盘点补货</div>
        </div>
    </div>

    @if(empty($locks))
        <div class="empty-box" style="padding: 32px;">暂无库存变动流水</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>流水动作</th>
                        <th>变动方向</th>
                        <th>变动数量</th>
                        <th>变动后快照 (售/租/锁/修)</th>
                        <th>机身 SN / 原因备注</th>
                        <th>记录时间 (UTC)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($locks as $lk)
                        <tr>
                            <td style="font-weight: 600;">{{ $lockTypeMap[$lk['lock_type']] ?? $lk['lock_type'] }}</td>
                            <td>
                                @if($lk['direction'] === 'lock')
                                    <span class="badge badge-amber">锁定 / 扣减</span>
                                @elseif($lk['direction'] === 'release')
                                    <span class="badge badge-green">释放回补</span>
                                @else
                                    <span class="badge badge-blue">盘点调整</span>
                                @endif
                            </td>
                            <td style="font-weight: 700;">{{ $lk['qty'] }}</td>
                            <td style="font-size: 12px; color: var(--text-secondary);">
                                可售 {{ $lk['snapshot']['sellable_stock'] ?? '-' }} · 可租 {{ $lk['snapshot']['rentable_stock'] ?? '-' }} · 在租 {{ $lk['snapshot']['rented_locked_qty'] ?? '-' }} · 维保 {{ $lk['snapshot']['maintenance_qty'] ?? '-' }}
                            </td>
                            <td style="font-size: 12.5px;">
                                @if(!empty($lk['device_sn']))
                                    <code>{{ $lk['device_sn'] }}</code> ·
                                @endif
                                {{ $lk['reason'] }}
                            </td>
                            <td style="font-size: 12px; color: var(--text-secondary);">{{ $lk['created_at'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- 弹窗 1：新增/编辑规格 SKU --}}
<div id="modal-sku" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-sku')">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title" id="modal-sku-title">新增多规格 SKU</h3>
            <button type="button" class="modal-close" onclick="closeMerchantModal('modal-sku')">✕</button>
        </div>
        <form method="POST" action="{{ route('shop.merchant.products.skus.store', $product['product_id']) }}">
            @csrf
            <input type="hidden" name="sku_id" id="sku-id-input">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label>规格名称</label>
                        <input type="text" name="name" id="sku-name-input" required placeholder="例如：EDU 算力增强版（含激光雷达）">
                    </div>
                    <div class="field">
                        <label>SKU 编码（留空自动生成）</label>
                        <input type="text" name="sku_code" id="sku-code-input" placeholder="例如：G1-EDU-PRO">
                    </div>
                    <div class="field">
                        <label>规格属性标签</label>
                        <input type="text" name="spec_label" id="sku-spec-input" placeholder="例如：100TOPS 算力 + 双电池包">
                    </div>
                    <div class="field">
                        <label>售价 / 日租 / 押金（元）</label>
                        <div class="form-grid-3">
                            <input type="number" step="0.01" min="0" name="price_yuan" id="sku-price-input" required placeholder="售价">
                            <input type="number" step="0.01" min="0" name="daily_rent_yuan" id="sku-rent-input" placeholder="日租">
                            <input type="number" step="0.01" min="0" name="deposit_yuan" id="sku-deposit-input" placeholder="押金">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>该 SKU 可售库存（台/件）</label>
                        <input type="number" min="0" name="sellable_stock" id="sku-sellable-input" required value="5">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>该 SKU 可租库存（台）</label>
                        <input type="number" min="0" name="rentable_stock" id="sku-rentable-input" required value="3">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-sku')">取消</button>
                <button type="submit" class="btn btn-primary">保存规格并同步总库存</button>
            </div>
        </form>
    </div>
</div>

{{-- 弹窗 2：新增租赁阶梯方案 --}}
<div id="modal-add-rental-plan" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-add-rental-plan')">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 class="modal-title">新增租赁阶梯方案与押金规则</h3>
            <button type="button" class="modal-close" onclick="closeMerchantModal('modal-add-rental-plan')">✕</button>
        </div>
        <form method="POST" action="{{ route('shop.merchant.products.rental-plans.store', $product['product_id']) }}">
            @csrf
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label>租赁方案名称</label>
                        <input type="text" name="name" required placeholder="例如：赛事备赛周租/月租阶梯计划">
                    </div>
                    <div class="field">
                        <label>计费周期单位</label>
                        <select name="period_unit">
                            <option value="day">按天计费 (day)</option>
                            <option value="week">按周计费 (week)</option>
                            <option value="month">按月计费 (month)</option>
                            <option value="quarter">按季计费 (quarter)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>最短起租 / 最长租期（天）</label>
                        <div class="form-grid">
                            <input type="number" min="1" name="min_days" required value="3">
                            <input type="number" min="1" name="max_days" value="90">
                        </div>
                    </div>
                    <div class="field">
                        <label>基准单期租金（元）</label>
                        <input type="number" step="0.01" min="0" name="base_price_yuan" required value="{{ $product['daily_rent_yuan'] }}">
                    </div>
                    <div class="field">
                        <label>阶梯折扣日租（满 7 天日租 / 满 30 天日租，元）</label>
                        <div class="form-grid">
                            <input type="number" step="0.01" min="0" name="tier_7_daily_yuan" placeholder="满7天日租(元)">
                            <input type="number" step="0.01" min="0" name="tier_30_daily_yuan" placeholder="满30天日租(元)">
                        </div>
                    </div>
                    <div class="field">
                        <label>押金风控模式与押金金额（元）</label>
                        <div class="form-grid">
                            <select name="deposit_mode">
                                <option value="full">全额押金</option>
                                <option value="ratio">按比例押金</option>
                                <option value="credit_waiver">企业信用免押</option>
                            </select>
                            <input type="number" step="0.01" min="0" name="deposit_yuan" required value="{{ $product['deposit_yuan'] }}">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>逾期滞纳日费（元/天）</label>
                        <input type="number" step="0.01" min="0" name="overdue_daily_yuan" value="300.00">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label>租转售买断价（元，选填）</label>
                        <input type="number" step="0.01" min="0" name="buyout_price_yuan" value="{{ $product['price_yuan'] }}">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-add-rental-plan')">取消</button>
                <button type="submit" class="btn btn-primary">保存租赁方案与押金规则</button>
            </div>
        </form>
    </div>
</div>

{{-- 弹窗 3：审核驳回 --}}
@if($product['review_status'] === 'in_review')
    <div id="modal-reject-review" class="modal-backdrop" onclick="if(event.target===this)closeMerchantModal('modal-reject-review')">
        <div class="modal-dialog modal-sm">
            <div class="modal-header">
                <h3 class="modal-title">驳回商品提审申请</h3>
                <button type="button" class="modal-close" onclick="closeMerchantModal('modal-reject-review')">✕</button>
            </div>
            <form method="POST" action="{{ route('shop.merchant.products.review-decision', $product['product_id']) }}">
                @csrf
                <input type="hidden" name="decision" value="reject">
                <div class="modal-body">
                    <div class="field" style="margin-bottom:0;">
                        <label>驳回原因说明（必填，商家可根据该原因修改后重新提审）</label>
                        <textarea name="reject_reason" rows="3" required placeholder="例如：请补充设备续航时间与额定负载参数说明，并核实租赁逾期日费标准。"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMerchantModal('modal-reject-review')">取消</button>
                    <button type="submit" class="btn btn-danger">确认驳回</button>
                </div>
            </form>
        </div>
    </div>
@endif

<script>
    function openSkuModal(skuId, name, code, spec, price, rent, deposit, sellable, rentable) {
        document.getElementById('modal-sku-title').textContent = skuId ? ('编辑规格 SKU · ' + code) : '新增多规格 SKU';
        document.getElementById('sku-id-input').value = skuId || '';
        document.getElementById('sku-name-input').value = name || '';
        document.getElementById('sku-code-input').value = code || '';
        document.getElementById('sku-spec-input').value = spec || '';
        document.getElementById('sku-price-input').value = price || '';
        document.getElementById('sku-rent-input').value = rent || '';
        document.getElementById('sku-deposit-input').value = deposit || '';
        document.getElementById('sku-sellable-input').value = sellable;
        document.getElementById('sku-rentable-input').value = rentable;
        openMerchantModal('modal-sku');
    }
</script>
@endsection
