@extends('shop::dev.layouts.app')

@section('title', '交易与授权订单 · 开发者中心')

@section('content')
    <div class="page-head">
        <div>
            <h1>交易与授权订单</h1>
            <p class="page-head-sub">追踪技能销售流水、买家授权发放记录、终端设备挂载激活进度及退款撤销全生命周期留痕。</p>
        </div>
        <div class="page-head-actions">
            <a class="btn ghost" href="{{ route('shop.dev.skills.index') }}">🧩 我的技能</a>
            <a class="btn ghost" href="{{ route('shop.dev.audits.index') }}">🛡️ 审核与日志</a>
        </div>
    </div>

    {{-- 销售额与授权生命周期 KPI 核心指标卡 --}}
    <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 20px;">
        <div class="kpi-card" style="border-top: 3px solid #2563EB;">
            <div class="kpi-label">累计销售总额 (GMV)</div>
            <div class="kpi-val" style="color: #2563EB;">¥{{ $metrics['gmv_yuan'] }}</div>
            <div class="kpi-foot">名下全部技能历史出单毛额合计</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #059669;">
            <div class="kpi-label">实际净销售额 (Net Sales)</div>
            <div class="kpi-val" style="color: #059669;">¥{{ $metrics['net_sales_yuan'] }}</div>
            <div class="kpi-foot">已扣除售后退款及撤单金额</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #6366F1;">
            <div class="kpi-label">授权总量 / 有效授权</div>
            <div class="kpi-val" style="color: #4F46E5;">{{ $metrics['total_entitlements'] }} <span style="font-size:14px;color:#64748B;font-weight:500;">/ {{ $metrics['active_count'] }} 有效</span></div>
            <div class="kpi-foot">累计发放凭证数与当前在保可用数</div>
        </div>
        <div class="kpi-card" style="border-top: 3px solid #D97706;">
            <div class="kpi-label">待挂载 / 退款撤销</div>
            <div class="kpi-val" style="color: #D97706;">{{ $metrics['pending_bind_count'] }} <span style="font-size:14px;color:#DC2626;font-weight:500;">/ {{ $metrics['revoked_count'] }} 撤销</span></div>
            <div class="kpi-foot">待买家设备录入与已退款失效条目</div>
        </div>
    </div>

    {{-- 筛选与状态切换栏 --}}
    <div class="card" style="padding: 18px 20px; margin-bottom: 18px;">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
            <div class="tab-pills" style="margin: 0;">
                <a href="{{ route('shop.dev.orders.index', array_merge(request()->except('status', 'page'), ['status' => ''])) }}"
                   class="tab-pill {{ empty($filters['status']) ? 'active' : '' }}">全部订单 ({{ $metrics['total_entitlements'] }})</a>
                <a href="{{ route('shop.dev.orders.index', array_merge(request()->except('status', 'page'), ['status' => 'active'])) }}"
                   class="tab-pill {{ ($filters['status'] ?? '') === 'active' ? 'active' : '' }}">生效中 ({{ $metrics['active_count'] }})</a>
                <a href="{{ route('shop.dev.orders.index', array_merge(request()->except('status', 'page'), ['status' => 'pending_bind'])) }}"
                   class="tab-pill {{ ($filters['status'] ?? '') === 'pending_bind' ? 'active' : '' }}">待挂载设备 ({{ $metrics['pending_bind_count'] }})</a>
                <a href="{{ route('shop.dev.orders.index', array_merge(request()->except('status', 'page'), ['status' => 'revoked'])) }}"
                   class="tab-pill {{ ($filters['status'] ?? '') === 'revoked' ? 'active' : '' }}">已撤销/退款 ({{ $metrics['revoked_count'] }})</a>
                <a href="{{ route('shop.dev.orders.index', array_merge(request()->except('status', 'page'), ['status' => 'expired'])) }}"
                   class="tab-pill {{ ($filters['status'] ?? '') === 'expired' ? 'active' : '' }}">已到期 ({{ $metrics['expired_count'] }})</a>
            </div>

            <span class="muted" style="font-size: 12.5px;">数据实时统计，买家信息依《个保法》脱敏保护</span>
        </div>

        <form method="GET" action="{{ route('shop.dev.orders.index') }}" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            @if (! empty($filters['status']))
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif

            <div style="min-width: 180px; flex: 1;">
                <select name="product_id" style="margin: 0; padding: 8px 12px; font-size: 13px;">
                    <option value="">全部技能商品</option>
                    @foreach ($ownedSkills as $s)
                        <option value="{{ $s->id }}" {{ ((string)($filters['product_id'] ?? '')) === ((string)$s->id) ? 'selected' : '' }}>
                            {{ $s->name }} ({{ $s->sku }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="min-width: 240px; flex: 1.5;">
                <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="搜索订单号 / 技能名称 / 授权许可 License Key / 买家" style="margin: 0; padding: 8px 12px; font-size: 13px;">
            </div>

            <button type="submit" class="btn small" style="margin: 0;">筛选查询</button>
            @if (! empty($filters['keyword']) || ! empty($filters['product_id']) || ! empty($filters['status']))
                <a href="{{ route('shop.dev.orders.index') }}" class="btn ghost small" style="margin: 0;">重置</a>
            @endif
        </form>
    </div>

    {{-- 订单列表表格 --}}
    <div class="card" style="padding: 0; overflow: hidden;">
        @if ($orders->isEmpty())
            <div class="empty-state">
                <div class="empty-state-icon">🧾</div>
                <h3 style="margin: 0 0 6px; font-size: 16px; color: var(--text-main);">暂无匹配的交易与授权订单</h3>
                <p class="muted" style="margin: 0; font-size: 13px;">买家在平台商场下单购买并完成支付后，系统将自动分配授权并记录在此处。</p>
            </div>
        @else
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 140px;">订单编号</th>
                            <th style="min-width: 180px;">技能商品与版本</th>
                            <th style="width: 110px;">销售金额</th>
                            <th style="width: 140px;">买家信息 (脱敏)</th>
                            <th style="min-width: 150px;">挂载终端设备</th>
                            <th style="width: 110px;">授权状态</th>
                            <th style="width: 150px;">下单与发放时间</th>
                            <th style="width: 90px; text-align: right;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $item)
                            <tr>
                                <td>
                                    <span style="font-weight: 600; font-family: monospace; color: var(--text-main);">{{ $item['order_no'] }}</span>
                                    <div class="muted" style="font-size: 11px;">#{{ $item['id'] }}</div>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-main); margin-bottom: 3px;">{{ $item['product_name'] }}</div>
                                    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                        <span class="badge" style="font-size: 11px; padding: 1px 6px;">{{ $item['version'] }}</span>
                                        <span class="muted" style="font-size: 11.5px; font-family: monospace;">{{ $item['product_sku'] }}</span>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight: 700; color: #0F172A; font-size: 14px;">¥{{ $item['amount_yuan'] }}</div>
                                    <div class="muted" style="font-size: 11px;">{{ $item['delivery_channel'] === 'api' ? '在线下发' : '人工配置' }}</div>
                                </td>
                                <td>
                                    <div style="font-weight: 500; color: var(--text-main);">{{ $item['buyer_name_masked'] }}</div>
                                    <div class="muted" style="font-size: 11px;">{{ $item['buyer_email_masked'] }}</div>
                                </td>
                                <td>
                                    @if ($item['device_sn'])
                                        <div style="font-weight: 600; font-family: monospace; color: #2563EB;">SN: {{ $item['device_sn'] }}</div>
                                        <div class="muted" style="font-size: 11px;">{{ $item['device_model'] ?: '通用机型' }}</div>
                                    @else
                                        <span class="badge" style="background: #F1F5F9; color: #64748B;">待终端挂载</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($item['status'] === 'active')
                                        <span class="badge ok">● 生效中</span>
                                    @elseif ($item['status'] === 'pending_bind')
                                        <span class="badge warn">待绑定</span>
                                    @elseif ($item['status'] === 'revoked')
                                        <span class="badge danger">已撤销</span>
                                    @elseif ($item['status'] === 'expired')
                                        <span class="badge">已到期</span>
                                    @else
                                        <span class="badge">{{ $item['status_label'] }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-size: 12.5px;">{{ $item['granted_at'] ?: '—' }}</div>
                                    @if ($item['status'] === 'revoked' && $item['revoked_at'])
                                        <div style="color: #DC2626; font-size: 11px;">撤销: {{ $item['revoked_at'] }}</div>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn ghost small" onclick="openOrderDetail({{ json_encode($item) }})" style="padding: 4px 10px;">
                                        详情
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div style="padding: 16px 20px; border-top: 1px solid var(--border-base); display: flex; justify-content: flex-end;">
                    {{ $orders->appends(request()->query())->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- 订单详情弹窗 Modal --}}
    <div id="dev-order-modal" class="dev-modal-backdrop" onclick="if(event.target === this) closeOrderDetail()">
        <div class="dev-modal" style="width: min(720px, 96vw);">
            <div class="dev-modal-head">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🧾</span>
                    <div>
                        <h3 id="modal-order-title" style="margin: 0; font-size: 16px;">订单详情与授权凭证</h3>
                        <div id="modal-order-sub" class="muted" style="font-size: 12px; margin-top: 2px;"></div>
                    </div>
                </div>
                <button type="button" class="dev-modal-close" onclick="closeOrderDetail()">×</button>
            </div>
            <div class="dev-modal-body">
                {{-- 核心状态与买家脱敏信息卡 --}}
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 18px;">
                    <div style="background: #F8FAFC; border: 1px solid var(--border-base); border-radius: 8px; padding: 12px 14px;">
                        <div class="muted" style="font-size: 11.5px; margin-bottom: 4px;">销售金额 / 状态</div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span id="modal-amount" style="font-size: 18px; font-weight: 700; color: #0F172A;"></span>
                            <span id="modal-status-badge"></span>
                        </div>
                    </div>
                    <div style="background: #F8FAFC; border: 1px solid var(--border-base); border-radius: 8px; padding: 12px 14px;">
                        <div class="muted" style="font-size: 11.5px; margin-bottom: 4px;">买家信息 (安全脱敏)</div>
                        <div id="modal-buyer" style="font-weight: 600; color: #0F172A; font-size: 13.5px;"></div>
                        <div id="modal-buyer-sub" class="muted" style="font-size: 11.5px;"></div>
                    </div>
                    <div style="background: #F8FAFC; border: 1px solid var(--border-base); border-radius: 8px; padding: 12px 14px;">
                        <div class="muted" style="font-size: 11.5px; margin-bottom: 4px;">挂载终端 / 机型</div>
                        <div id="modal-device" style="font-weight: 600; color: #2563EB; font-size: 13.5px;"></div>
                        <div id="modal-device-sub" class="muted" style="font-size: 11.5px;"></div>
                    </div>
                </div>

                {{-- 授权凭证 Key 展示 --}}
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 14px 16px; margin-bottom: 18px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 12px; font-weight: 600; color: #1E40AF;">授权许可凭证 (License Key)</span>
                        <button type="button" class="btn ghost small" onclick="copyLicenseKey()" style="background:#FFF; padding: 2px 8px; font-size: 11.5px;">复制凭证</button>
                    </div>
                    <div id="modal-license-key" style="font-family: monospace; font-size: 13.5px; font-weight: 600; color: #1E3A8A; word-break: break-all;"></div>
                </div>

                {{-- 撤销/取消警示提示（若有） --}}
                <div id="modal-cancel-box" class="notice" style="display: none; background: #FEF2F2; border-color: #FECACA; color: #991B1B; margin-bottom: 18px;">
                    <strong>⚠️ 该授权已被撤销 / 退款</strong>
                    <div id="modal-cancel-reason" style="margin-top: 4px; font-size: 12.5px;"></div>
                </div>

                {{-- 全链路履约与流转时间线 --}}
                <div style="margin-top: 14px;">
                    <h4 style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: var(--text-main);">全链路生命周期时间线</h4>
                    <div id="modal-timeline" style="border-left: 2px solid #E2E8F0; margin-left: 10px; padding-left: 16px;"></div>
                </div>
            </div>
            <div class="dev-modal-foot">
                <button type="button" class="btn ghost" onclick="closeOrderDetail()">关闭</button>
            </div>
        </div>
    </div>

    <script>
        let currentModalOrder = null;

        function openOrderDetail(order) {
            currentModalOrder = order;
            document.getElementById('modal-order-title').textContent = '订单 ' + order.order_no;
            document.getElementById('modal-order-sub').textContent = '商品: ' + order.product_name + ' (' + order.version + ') · 交付条目 #' + order.id;
            document.getElementById('modal-amount').textContent = '¥' + order.amount_yuan;

            const badgeEl = document.getElementById('modal-status-badge');
            if (order.status === 'active') {
                badgeEl.className = 'badge ok';
                badgeEl.textContent = '生效中';
            } else if (order.status === 'pending_bind') {
                badgeEl.className = 'badge warn';
                badgeEl.textContent = '待挂载设备';
            } else if (order.status === 'revoked') {
                badgeEl.className = 'badge danger';
                badgeEl.textContent = '已撤销/退款';
            } else {
                badgeEl.className = 'badge';
                badgeEl.textContent = order.status_label || order.status;
            }

            document.getElementById('modal-buyer').textContent = order.buyer_name_masked || '机甲用户';
            document.getElementById('modal-buyer-sub').textContent = '账号: ' + (order.buyer_email_masked || '—');

            document.getElementById('modal-device').textContent = order.device_sn ? ('SN: ' + order.device_sn) : '尚未挂载';
            document.getElementById('modal-device-sub').textContent = order.device_model ? ('机型: ' + order.device_model) : '等待买家终端填报';

            document.getElementById('modal-license-key').textContent = order.license_key || '—';

            const cancelBox = document.getElementById('modal-cancel-box');
            if (order.status === 'revoked') {
                cancelBox.style.display = 'block';
                document.getElementById('modal-cancel-reason').textContent = '撤销原因：' + (order.cancel_reason || '用户发起退款或订单撤单');
            } else {
                cancelBox.style.display = 'none';
            }

            const timelineEl = document.getElementById('modal-timeline');
            timelineEl.innerHTML = '';
            if (Array.isArray(order.timeline) && order.timeline.length > 0) {
                order.timeline.forEach((node, idx) => {
                    const itemDiv = document.createElement('div');
                    itemDiv.style.position = 'relative';
                    itemDiv.style.marginBottom = (idx === order.timeline.length - 1) ? '0' : '16px';

                    let dotColor = '#94A3B8';
                    if (node.status === 'done') dotColor = '#059669';
                    else if (node.status === 'revoked') dotColor = '#DC2626';
                    else if (node.status === 'wait') dotColor = '#D97706';

                    itemDiv.innerHTML = `
                        <div style="position: absolute; left: -22px; top: 3px; width: 10px; height: 10px; border-radius: 50%; background: ${dotColor}; box-shadow: 0 0 0 3px #FFF;"></div>
                        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 8px;">
                            <span style="font-weight: 600; font-size: 13.5px; color: #0F172A;">${node.title}</span>
                            <span class="muted" style="font-size: 11.5px;">${node.time || '—'}</span>
                        </div>
                        <div class="muted" style="font-size: 12.5px; margin-top: 2px;">${node.desc || ''}</div>
                    `;
                    timelineEl.appendChild(itemDiv);
                });
            } else {
                timelineEl.innerHTML = '<span class="muted" style="font-size: 12.5px;">暂无节点记录</span>';
            }

            document.getElementById('dev-order-modal').classList.add('open');
        }

        function closeOrderDetail() {
            document.getElementById('dev-order-modal').classList.remove('open');
        }

        function copyLicenseKey() {
            if (!currentModalOrder || !currentModalOrder.license_key) return;
            navigator.clipboard.writeText(currentModalOrder.license_key).then(() => {
                alert('授权许可凭证 (License Key) 已成功复制至剪贴板');
            }).catch(() => {
                prompt('请手动复制授权凭证：', currentModalOrder.license_key);
            });
        }

        // 支持 URL 带 view_order_id 直接自动打开
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const viewId = urlParams.get('view_order_id');
            if (viewId) {
                const targetOrder = @json($orders->items()).find(o => String(o.id) === String(viewId));
                if (targetOrder) {
                    openOrderDetail(targetOrder);
                }
            }
        });
    </script>
@endsection
