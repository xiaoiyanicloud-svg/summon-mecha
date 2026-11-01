@extends('shop::dev.layouts.app')

@section('title', '我的技能 · 开发者中心')

@section('content')
    @php
        $totalSkills = $skills->count();
        $onlineSkills = $skills->where('status', 1)->count();
        $draftSkills = $totalSkills - $onlineSkills;
        $totalEntitlements = $skills->sum(fn ($s) => (int) ($s->entitlements_count ?? 0));
        $versionStatusMap = [
            'draft'     => ['草稿', ''],
            'in_review' => ['审核中', 'info'],
            'rejected'  => ['已驳回', 'danger'],
            'published' => ['已发布', 'ok'],
            'archived'  => ['已归档', ''],
        ];
    @endphp

    <div class="page-head">
        <div>
            <h1>我的技能</h1>
            <p class="page-head-sub">管理名下机器人技能商品、版本迭代、交付物安检与上架申请。</p>
        </div>
        <div class="page-head-actions">
            {{-- 未实名：不跳创建页，改为打开实名引导弹窗（S-12d）；已实名正常跳转。
                 判定取自 createGate（DeveloperSkillService::canCreateSkill() 唯一实现），门户不重算。 --}}
            @if ($createGate['ok'])
                <a class="btn" href="{{ route('shop.dev.skills.create') }}">＋ 创建技能</a>
            @else
                <a class="btn" href="#realname-modal">＋ 创建技能</a>
            @endif
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">名下技能总数</div>
            <div class="kpi-val">{{ $totalSkills }}</div>
            <div class="kpi-foot">按当前开发者主体隔离</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">已上架在售</div>
            <div class="kpi-val" style="color:var(--success)">{{ $onlineSkills }}</div>
            <div class="kpi-foot">买家可在商城直接购买</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">未上架 / 草稿</div>
            <div class="kpi-val" style="color:var(--warning)">{{ $draftSkills }}</div>
            <div class="kpi-foot">新建即草稿，审核后申请上架</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">累计发放授权数</div>
            <div class="kpi-val" style="color:var(--primary)">{{ $totalEntitlements }}</div>
            <div class="kpi-foot">实时汇总自 skill_entitlements</div>
        </div>
    </div>

    <div class="card">
        <div class="filter-bar">
            <div class="tab-bar" role="tablist">
                <button type="button" class="tab-pill active" onclick="filterSkillRows('all', this)">全部 ({{ $totalSkills }})</button>
                <button type="button" class="tab-pill" onclick="filterSkillRows('online', this)">已上架 ({{ $onlineSkills }})</button>
                <button type="button" class="tab-pill" onclick="filterSkillRows('offline', this)">未上架 ({{ $draftSkills }})</button>
            </div>
            <div style="min-width:240px">
                <input type="text" id="skill-keyword-filter" placeholder="搜索技能名称或 SKU…" oninput="filterSkillByKeyword(this.value)">
            </div>
        </div>

        @if ($skills->isNotEmpty())
            <table id="dev-skills-table">
                <thead>
                    <tr>
                        <th style="width:36%">技能名称 / SKU</th>
                        <th>买断定价</th>
                        <th>版本迭代</th>
                        <th>已售授权</th>
                        <th>上架状态</th>
                        <th style="text-align:right">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($skills as $skill)
                        @php
                            [$vLabel, $vBadge] = $versionStatusMap[$skill->latest_version_status ?? ''] ?? ['暂无版本', ''];
                        @endphp
                        <tr class="skill-row"
                            data-online="{{ $skill->status ? 'online' : 'offline' }}"
                            data-keyword="{{ mb_strtolower($skill->name.' '.$skill->sku) }}">
                            <td>
                                <a href="{{ route('shop.dev.skills.show', $skill->id) }}" style="font-size:14.5px">
                                    <strong>{{ $skill->name }}</strong>
                                </a>
                                <div class="muted" style="margin-top:3px">
                                    SKU: <span class="code-chip">{{ $skill->sku }}</span>
                                    @if (! empty($skill->short_description))
                                        · {{ \Illuminate\Support\Str::limit($skill->short_description, 42) }}
                                    @endif
                                </div>
                            </td>
                            <td>
                                <strong>￥{{ number_format((float) $skill->price, 2) }}</strong>
                            </td>
                            <td>
                                @if (! empty($skill->latest_version))
                                    <span class="code-chip">v{{ $skill->latest_version }}</span>
                                    <span class="badge {{ $vBadge }}" style="margin-left:4px">{{ $vLabel }}</span>
                                    <div class="muted" style="margin-top:2px">共 {{ $skill->versions_count ?? 1 }} 个版本</div>
                                @else
                                    <span class="muted">尚未创建版本</span>
                                @endif
                            </td>
                            <td>
                                <span style="font-weight:600">{{ $skill->entitlements_count ?? 0 }}</span> 份
                            </td>
                            <td>
                                {{-- 未上架是常态：新建即草稿，上架由审核通过后决定 --}}
                                <span class="badge {{ $skill->status ? 'ok' : '' }}">
                                    {{ $skill->status ? '已上架' : '未上架' }}
                                </span>
                            </td>
                            <td style="text-align:right;white-space:nowrap">
                                <a class="btn small ghost" href="{{ route('shop.dev.skills.edit', $skill->id) }}">编辑信息</a>
                                <a class="btn small" href="{{ route('shop.dev.skills.show', $skill->id) }}">版本与审核 →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty-state">
                <div class="empty-state-icon">🧩</div>
                <p class="muted" style="font-size:14px">还没有技能。点右上角「创建技能」开始。</p>
            </div>
        @endif
    </div>

    {{-- 未实名时的实名引导弹窗（纯 CSS :target，partial 复用） --}}
    @unless ($createGate['ok'])
        @include('shop::dev.partials.realname-modal')
    @endunless

    <script>
        function filterSkillRows(mode, btn) {
            document.querySelectorAll('.tab-pill').forEach(function (b) { b.classList.remove('active'); });
            if (btn) btn.classList.add('active');
            document.querySelectorAll('#dev-skills-table .skill-row').forEach(function (tr) {
                var match = (mode === 'all') || (tr.getAttribute('data-online') === mode);
                tr.style.display = match ? '' : 'none';
            });
        }
        function filterSkillByKeyword(kw) {
            var q = (kw || '').toLowerCase().trim();
            document.querySelectorAll('#dev-skills-table .skill-row').forEach(function (tr) {
                var hay = tr.getAttribute('data-keyword') || '';
                tr.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        }
    </script>
@endsection
