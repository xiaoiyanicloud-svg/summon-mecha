@extends('shop::dev.layouts.app')

@section('title', '审核状态 · 开发者中心')

@section('content')
    @php
        $passCount = $records->where('result', \Webkul\Shop\Models\AuditRecord::RESULT_PASS)->count();
        $rejectCount = $records->count() - $passCount;
        $opLogs = $operationLogs ?? collect();
        $actionLabels = [
            'skill.update'            => ['修改技能信息/定价', 'info'],
            'skill.offline'           => ['技能下架（停售）', 'danger'],
            'skill.version.withdraw'  => ['撤回版本提审', 'warn'],
            'skill.listing.requested' => ['申请平台上架', 'ok'],
            'skill.version.update'    => ['更新版本配置/指引', 'info'],
            'skill.asset.delete'      => ['移除版本交付物', 'warn'],
            'skill.version.evidence'  => ['登记机型适配验证', 'purple'],
        ];
    @endphp

    <div class="page-head">
        <div>
            <h1>审核状态</h1>
            <p class="page-head-sub">查看版本 L0 机审 / L2 人工审核结论与驳回建议，以及本主体敏感操作审计留痕。</p>
        </div>
        <div class="page-head-actions">
            <a class="btn ghost small" href="{{ route('shop.dev.skills.index') }}">🧩 返回我的技能</a>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">累计审核记录</div>
            <div class="kpi-val">{{ $records->count() }}</div>
            <div class="kpi-foot">含 L0 机审与 L2 人工复核</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">审核通过次数</div>
            <div class="kpi-val" style="color:var(--success)">{{ $passCount }}</div>
            <div class="kpi-foot">满足对应层级准入要求</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">被驳回 / 待整改</div>
            <div class="kpi-val" style="color:var(--danger)">{{ $rejectCount }}</div>
            <div class="kpi-foot">附具体驳回原因与整改建议</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">主体操作留痕</div>
            <div class="kpi-val" style="color:var(--primary)">{{ $opLogs->count() }}</div>
            <div class="kpi-foot">改价 / 下架 / 撤回 / 申请上架</div>
        </div>
    </div>

    {{-- 区块 1：版本审核记录 --}}
    <div class="card">
        <div class="card-head">
            <h2>🛡️ 技能版本审核流水</h2>
            <div class="tab-bar">
                <button type="button" class="tab-pill active" onclick="filterAuditRows('all', this)">全部 ({{ $records->count() }})</button>
                <button type="button" class="tab-pill" onclick="filterAuditRows('pass', this)">通过 ({{ $passCount }})</button>
                <button type="button" class="tab-pill" onclick="filterAuditRows('reject', this)">驳回 ({{ $rejectCount }})</button>
            </div>
        </div>

        @if ($records->isNotEmpty())
            <table id="dev-audit-table">
                <thead>
                    <tr>
                        <th style="width:100px">审核层级</th>
                        <th style="width:220px">所属技能 / 版本</th>
                        <th style="width:100px">审核结论</th>
                        <th>原因明细与整改建议</th>
                        <th style="width:165px">审核时间</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $record)
                        @php
                            $isPass = $record->result == \Webkul\Shop\Models\AuditRecord::RESULT_PASS;
                        @endphp
                        <tr class="audit-row" data-result="{{ $isPass ? 'pass' : 'reject' }}">
                            <td><span class="badge info">L{{ $record->layer }}</span></td>
                            <td>
                                @if ($record->skill_id)
                                    <a href="{{ route('shop.dev.skills.show', $record->skill_id) }}">
                                        <strong>{{ $record->skill_name ?? ('技能 #'.$record->skill_id) }}</strong>
                                    </a>
                                @else
                                    <span>{{ $record->skill_name ?? '—' }}</span>
                                @endif
                                @if (! empty($record->version_number))
                                    <div class="muted" style="margin-top:2px">版本 <span class="code-chip">v{{ $record->version_number }}</span></div>
                                @endif
                            </td>
                            <td>
                                <span class="badge {{ $isPass ? 'ok' : 'danger' }}">
                                    {{ $isPass ? '通过' : '驳回' }}
                                </span>
                            </td>
                            <td>
                                {{-- 驳回理由必须让开发者看得见：否则他只能靠群里问人 --}}
                                @if ($record->reason_detail)
                                    <div>{{ $record->reason_category }}：{{ $record->reason_detail }}</div>
                                @else
                                    <span class="muted">无</span>
                                @endif
                                @if ($record->suggestion)
                                    <div class="muted" style="margin-top:3px">建议：{{ $record->suggestion }}</div>
                                @endif
                            </td>
                            <td class="muted">{{ optional($record->created_at)->toDateTimeString() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted">还没有审核记录。提交审核后这里会显示结果与驳回理由。</p>
        @endif
    </div>

    {{-- 区块 2：开发者本主体操作审计留痕（三级/四级可追溯闭环） --}}
    <div class="card">
        <div class="card-head">
            <h2>📜 开发者关键操作审计日志（Operation Log）</h2>
            <span class="badge">不可篡改 · 仅展示本主体记录</span>
        </div>

        @if ($opLogs->isNotEmpty())
            <table>
                <thead>
                    <tr>
                        <th style="width:190px">动作类型</th>
                        <th style="width:150px">目标对象</th>
                        <th>变更上下文摘要</th>
                        <th style="width:165px">记录时间</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($opLogs as $log)
                        @php
                            [$actLabel, $actBadge] = $actionLabels[$log->action] ?? [$log->action, ''];
                            $ctx = is_array($log->context) ? $log->context : [];
                        @endphp
                        <tr>
                            <td>
                                <span class="badge {{ $actBadge }}">{{ $actLabel }}</span>
                                <div class="muted" style="margin-top:2px"><code>{{ $log->action }}</code></div>
                            </td>
                            <td>
                                <span class="code-chip">{{ $log->target_type }} #{{ $log->target_id }}</span>
                            </td>
                            <td class="muted">
                                @if ($log->action === 'skill.update')
                                    定价：￥{{ $ctx['before']['price'] ?? '—' }} → <strong>￥{{ $ctx['after']['price'] ?? '—' }}</strong>
                                    （名称：{{ $ctx['after']['name'] ?? '—' }}）
                                @elseif ($log->action === 'skill.offline')
                                    下架原因：<strong>{{ $ctx['reason'] ?? '—' }}</strong>
                                @elseif ($log->action === 'skill.listing.requested')
                                    申请上架版本：<span class="code-chip">v{{ $ctx['version'] ?? '—' }}</span>（商品 #{{ $ctx['product_id'] ?? '—' }}）
                                @elseif ($log->action === 'skill.version.withdraw')
                                    撤回版本：<span class="code-chip">v{{ $ctx['version'] ?? '—' }}</span>（{{ $ctx['from'] ?? '' }} → {{ $ctx['to'] ?? '' }}）
                                @elseif ($log->action === 'skill.version.update')
                                    更新版本 <span class="code-chip">v{{ $ctx['version'] ?? '—' }}</span>（通道: {{ $ctx['channel'] ?? '—' }}, 授权: {{ $ctx['license_mode'] ?? '—' }}）
                                @elseif ($log->action === 'skill.asset.delete')
                                    移除交付物：{{ ($ctx['file_name'] ?? null) ?: ($ctx['carrier_type'] ?? '—') }}（版本 v{{ $ctx['version'] ?? '—' }}）
                                @elseif ($log->action === 'skill.version.evidence')
                                    机型 <span class="code-chip">{{ $ctx['model_code'] ?? '—' }}</span> · {{ $ctx['source'] ?? '' }} / {{ $ctx['evidence_type'] ?? '' }} → <strong>{{ $ctx['result'] ?? '' }}</strong>
                                @else
                                    <code>{{ json_encode($ctx, JSON_UNESCAPED_UNICODE) }}</code>
                                @endif
                            </td>
                            <td class="muted">{{ optional($log->created_at)->toDateTimeString() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted">暂无关键操作留痕记录。</p>
        @endif
    </div>

    <script>
        function filterAuditRows(mode, btn) {
            if (btn && btn.parentNode) {
                btn.parentNode.querySelectorAll('.tab-pill').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
            }
            document.querySelectorAll('#dev-audit-table .audit-row').forEach(function (tr) {
                var show = (mode === 'all') || (tr.getAttribute('data-result') === mode);
                tr.style.display = show ? '' : 'none';
            });
        }
    </script>
@endsection
