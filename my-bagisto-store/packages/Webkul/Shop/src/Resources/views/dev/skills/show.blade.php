@extends('shop::dev.layouts.app')

@section('title', $product->name.' · 开发者中心')

@section('content')
    @php
        $licenseLabels = [
            'per_device'  => '按单台设备绑定 (per_device)',
            'per_order'   => '按订单授权 (per_order)',
            'per_account' => '按买家账号共享 (per_account)',
            'unlimited'   => '不限激活次数 (unlimited)',
        ];
        $riskLabels = [
            'low'    => ['低风险 (low)', 'ok'],
            'medium' => ['中风险 (medium)', 'warn'],
            'high'   => ['高风险 (high)', 'danger'],
        ];
        $vStatusBadges = [
            'draft'     => ['草稿 (draft)', ''],
            'in_review' => ['审核中 (in_review)', 'info'],
            'rejected'  => ['已驳回 (rejected)', 'danger'],
            'published' => ['已发布 (published)', 'ok'],
            'archived'  => ['已归档 (archived)', ''],
        ];
    @endphp

    <div class="page-head">
        <div>
            <h1>
                {{ $product->name }}
                <span class="badge {{ $product->status ? 'ok' : '' }}" style="vertical-align:middle;margin-left:6px">
                    {{ $product->status ? '已上架' : '未上架' }}
                </span>
            </h1>
            <p class="page-head-sub">
                SKU: <span class="code-chip">{{ $product->sku }}</span> ｜
                买断定价：<strong>￥{{ number_format((float) $product->price, 2) }}</strong>
                @if (! empty($product->short_description))
                    ｜ {{ $product->short_description }}
                @endif
            </p>
        </div>
        <div class="page-head-actions">
            <a class="btn ghost small" href="{{ route('shop.dev.skills.index') }}">← 返回列表</a>
            <a class="btn ghost small" href="{{ route('shop.dev.skills.edit', $product->id) }}">✏️ 编辑信息</a>
            <button type="button" class="btn small" onclick="openDevModal('dev-modal-create-version')">＋ 新建版本</button>
        </div>
    </div>

    {{-- 下架：已上架的技能才显示。理由必填、会留痕；下架不回收已售授权；无自助重新上架入口。 --}}
    @if ($product->status)
        <div class="card" style="border-color:#FECACA;background:#FFFBFB">
            <div class="card-head" style="border-bottom-color:#FEE2E2">
                <h2>🛑 下架技能（停止新增销售）</h2>
                <span class="badge danger">高敏操作 · 自动记入审计日志</span>
            </div>
            <form method="POST" action="{{ route('shop.dev.skills.offline', $product->id) }}">
                @csrf
                <div class="row" style="align-items:flex-end">
                    <div style="flex:3">
                        <label for="offline_reason" style="margin-top:0">下架理由（必填，会留痕）</label>
                        <input id="offline_reason" type="text" name="reason" required minlength="2" maxlength="500"
                               placeholder="例如：技能存在缺陷，暂时下架整改">
                    </div>
                    <div style="flex:0 0 auto">
                        <button type="submit" class="btn danger">下架</button>
                    </div>
                </div>
                <p class="muted" style="margin:8px 0 0">
                    下架只停<b>新增销售</b>：已购买用户的授权不受影响，设备继续可用。
                    重新上架请联系平台运营，或修改后重新提审 → 发布（开发者不能自助重新上架）。
                </p>
            </form>
        </div>
    @endif

    {{-- 新建版本：支持完整二三四级参数（同时提供页面卡片与弹窗快捷入口，兼容无 JS 自动化测试） --}}
    <div class="card" id="create-version-card">
        <div class="card-head">
            <h2>📦 新建版本</h2>
            <span class="badge info">支持通道 / 授权规则 / 风险等级 / 权限声明 / 人工安装指引</span>
        </div>
        <form method="POST" action="{{ route('shop.dev.skills.versions.store', $product->id) }}">
            @csrf
            <div class="row">
                <div>
                    <label for="version">版本号 <span style="color:var(--danger)">*</span></label>
                    <input id="version" type="text" name="version" placeholder="1.0.0" required maxlength="32">
                </div>
                <div>
                    <label for="channel">发布通道</label>
                    <select id="channel" name="channel">
                        @foreach (\Webkul\Shop\Models\SkillVersion::CHANNELS as $ch)
                            <option value="{{ $ch }}">
                                {{ \Webkul\Shop\Models\SkillVersion::CHANNEL_LABELS[$ch] ?? $ch }} ({{ $ch }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="license_mode">授权模式</label>
                    <select id="license_mode" name="license_mode">
                        @foreach (\Webkul\Shop\Models\SkillVersion::LICENSE_MODES as $lm)
                            <option value="{{ $lm }}">{{ $licenseLabels[$lm] ?? $lm }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="risk_level">安全风险等级</label>
                    <select id="risk_level" name="risk_level">
                        <option value="low">低风险 (low)</option>
                        <option value="medium">中风险 (medium)</option>
                        <option value="high">高风险 (high)</option>
                    </select>
                </div>
            </div>

            <div class="row" style="margin-top:4px">
                <div>
                    <label for="max_activations">最大激活设备数（可选）</label>
                    <input id="max_activations" type="number" name="max_activations" min="1" max="100000" placeholder="如：3（留空为按模式默认）">
                </div>
                <div>
                    <label for="valid_days">授权有效天数（可选）</label>
                    <input id="valid_days" type="number" name="valid_days" min="1" max="36500" placeholder="留空表示买断永久有效">
                </div>
                <div>
                    <label for="min_robot_version">最低固件/系统版本（可选）</label>
                    <input id="min_robot_version" type="text" name="min_robot_version" maxlength="64" placeholder="如：>=2.1.0">
                </div>
                <div>
                    <label for="target_models">声明适配机型（逗号分隔）</label>
                    <input id="target_models" type="text" name="target_models" maxlength="500" placeholder="如：Unitree-G1, AgiBot-A2">
                </div>
            </div>

            <div class="row" style="margin-top:4px">
                <div>
                    <label>支持的交付通道</label>
                    <div style="display:flex;gap:16px;padding:8px 0;flex-wrap:wrap">
                        @foreach (\Webkul\Shop\Models\SkillAsset::DELIVERY_CHANNELS as $dc)
                            <label style="display:inline-flex;align-items:center;gap:6px;margin:0;font-weight:500;cursor:pointer">
                                <input type="checkbox" name="delivery_channels[]" value="{{ $dc }}" @checked($dc === 'manual') style="width:auto">
                                <span>{{ $dc === 'agent' ? 'Agent 在线下发 (agent)' : '下载 + 人工安装 (manual)' }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div style="flex:2">
                    <label>声明式硬件权限（买家风险告知可见）</label>
                    <div style="display:flex;gap:14px;padding:8px 0;flex-wrap:wrap">
                        @foreach (\Webkul\Shop\Services\SkillPackage\SkillManifest::PERMISSION_LABELS as $permCode => $permLabel)
                            <label style="display:inline-flex;align-items:center;gap:5px;margin:0;font-weight:500;cursor:pointer">
                                <input type="checkbox" name="permissions[]" value="{{ $permCode }}" style="width:auto">
                                <span>{{ $permLabel }} <span class="muted">({{ $permCode }})</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top:4px">
                <div>
                    <label for="changelog">变更说明</label>
                    <input id="changelog" type="text" name="changelog" maxlength="2000" placeholder="说明本版本新增特性、修复问题或适配变动">
                </div>
                <div>
                    <label for="install_guide">人工安装指引（可选，人工交付通道时展示给买家）</label>
                    <textarea id="install_guide" name="install_guide" rows="2" style="width:100%;min-height:42px" placeholder="如：解压后放置于 /opt/skills/...，适用于 manual 交付通道"></textarea>
                </div>
            </div>

            <div style="margin-top:14px">
                <button type="submit">创建版本</button>
            </div>
        </form>
    </div>

    {{-- 版本列表（每个版本具备完整的三级/四级操作闭环） --}}
    @forelse ($versions as $version)
        @php
            [$vsText, $vsBadge] = $vStatusBadges[$version->status] ?? [$version->status, ''];
            $isEditableVersion = in_array($version->status, [\Webkul\Shop\Models\SkillVersion::STATUS_DRAFT, \Webkul\Shop\Models\SkillVersion::STATUS_REJECTED], true);
            $compatModels = collect($version->compatibility['models'] ?? [])->pluck('model')->filter()->values()->all();
            $defaultFileAsset = $version->assets->first(fn ($a) => $a->isFile());
            $currentAssetHash = $defaultFileAsset?->file_hash;
            $versionAudits = ($auditByVersion[$version->id] ?? collect());
        @endphp

        <div class="card" id="version-card-{{ $version->id }}">
            <div class="card-head">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <h2 style="margin:0">
                        版本 {{ $version->version }}
                    </h2>
                    <span class="badge {{ $version->status === 'published' ? 'ok' : $vsBadge }}">{{ $version->status }}</span>
                    <span class="badge info">
                        通道 {{ $version->channel }}（{{ \Webkul\Shop\Models\SkillVersion::CHANNEL_LABELS[$version->channel] ?? $version->channel }}）
                    </span>
                    @if ($version->risk_level)
                        @php([$rText, $rClass] = $riskLabels[$version->risk_level] ?? [$version->risk_level, ''])
                        <span class="badge {{ $rClass }}">{{ $rText }}</span>
                    @endif
                </div>

                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <a class="btn ghost small" href="{{ route('shop.dev.versions.sample', ['versionId' => $version->id, 'kind' => 'motion']) }}">⬇️ 下载动作类模板</a>
                    <a class="btn ghost small" href="{{ route('shop.dev.versions.sample', ['versionId' => $version->id, 'kind' => 'app']) }}">⬇️ 下载应用类模板</a>
                    @if ($isEditableVersion)
                        <button type="button" class="btn ghost small" onclick="openDevModal('dev-modal-edit-version-{{ $version->id }}')">
                            ⚙️ 编辑版本配置
                        </button>
                    @endif
                    <button type="button" class="btn ghost small" onclick="openDevModal('dev-modal-evidence-{{ $version->id }}')">
                        🧪 登记适配验证
                    </button>
                </div>
            </div>

            {{-- 三级：版本配置、权限声明与人工安装指引概览 --}}
            <div class="sub-panel" style="margin-top:0;margin-bottom:16px">
                <div class="row" style="gap:18px">
                    <div>
                        <div class="muted">授权规则</div>
                        <div style="font-weight:600;margin-top:2px">
                            {{ $licenseLabels[$version->license_mode] ?? ($version->license_mode ?: 'per_device') }}
                            @if ($version->max_activations)
                                · 最多激活 {{ $version->max_activations }} 台
                            @endif
                            @if ($version->valid_days)
                                · 有效期 {{ $version->valid_days }} 天
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="muted">交付通道 & 最低固件</div>
                        <div style="font-weight:600;margin-top:2px">
                            @if (! empty($version->delivery_channels))
                                {{ implode(' / ', (array) $version->delivery_channels) }}
                            @else
                                manual（默认）
                            @endif
                            @if ($version->min_robot_version)
                                · 固件 {{ $version->min_robot_version }}
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="muted">声明适配机型</div>
                        <div style="margin-top:2px">
                            @if ($compatModels)
                                @foreach ($compatModels as $cm)
                                    <span class="code-chip">{{ $cm }}</span>
                                @endforeach
                            @else
                                <span class="muted">未显式限定（以包内 manifest.json 为准）</span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="muted">声明硬件权限</div>
                        <div style="margin-top:2px">
                            @if (! empty($version->permissions))
                                @foreach ((array) $version->permissions as $pm)
                                    <span class="badge purple">{{ \Webkul\Shop\Services\SkillPackage\SkillManifest::PERMISSION_LABELS[$pm] ?? $pm }}</span>
                                @endforeach
                            @else
                                <span class="muted">无特殊权限声明</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($version->changelog || $version->install_guide)
                    <div style="margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-base);display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
                        @if ($version->changelog)
                            <div>
                                <span class="muted">变更说明：</span>
                                <span>{{ $version->changelog }}</span>
                            </div>
                        @endif
                        @if ($version->install_guide)
                            <div>
                                <span class="muted">人工安装指引：</span>
                                <span>{{ $version->install_guide }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- 三级/四级：已上传的交付物清单（含 SHA-256 指纹、自检下载、草稿移除） --}}
            <h3 style="font-size:14px;margin:0 0 8px">📁 版本交付物（Assets）</h3>
            @if ($version->assets->isNotEmpty())
                <table>
                    <thead>
                        <tr>
                            <th>载体</th>
                            <th>文件 / 载荷</th>
                            <th>大小</th>
                            <th>启动入口 & SHA-256 指纹</th>
                            <th>状态</th>
                            <th style="text-align:right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($version->assets as $asset)
                            <tr>
                                <td>
                                    <strong>{{ \Webkul\Shop\Models\SkillAsset::CARRIER_LABELS[$asset->carrier_type] ?? $asset->carrier_type }}</strong>
                                    <span class="muted">（{{ $asset->carrier_type }}）</span>
                                </td>
                                <td>
                                    @if ($asset->file_name)
                                        <span class="code-chip">{{ $asset->file_name }}</span>
                                    @elseif (! empty($asset->payload))
                                        <span class="code-chip">{{ \Illuminate\Support\Str::limit(json_encode($asset->payload, JSON_UNESCAPED_UNICODE), 48) }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $asset->file_size ? number_format($asset->file_size / 1024, 1).' KB' : '—' }}</td>
                                <td>
                                    @if ($asset->entrypoint)
                                        <div>入口: <span class="code-chip">{{ $asset->entrypoint }}</span></div>
                                    @endif
                                    @if ($asset->file_hash)
                                        <div class="muted" title="{{ $asset->file_hash }}">
                                            SHA-256: <code>{{ substr($asset->file_hash, 0, 16) }}…</code>
                                        </div>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($asset->fileExists())
                                        <span class="badge ok">已落盘</span>
                                    @elseif (! $asset->isFileBacked() && ! empty($asset->payload))
                                        <span class="badge info">云端载荷已就绪</span>
                                    @else
                                        <span class="badge warn">无文件</span>
                                    @endif
                                </td>
                                <td style="text-align:right;white-space:nowrap">
                                    @if ($asset->fileExists())
                                        <a class="btn ghost small" href="{{ route('shop.dev.assets.download', $asset->id) }}">⬇️ 自检下载</a>
                                    @endif
                                    @if ($isEditableVersion)
                                        <form method="POST" action="{{ route('shop.dev.assets.destroy', $asset->id) }}" style="display:inline"
                                              onsubmit="return confirm('确认移除该交付物吗？')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn danger small">移除</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted" style="margin:6px 0 12px">还没有交付物。请在下方上传标准 <code>.skill</code> 包或配置载荷。</p>
            @endif

            {{-- 上传技能包 --}}
            <div class="sub-panel" style="margin-top:12px">
                <div style="font-weight:600;font-size:13px;margin-bottom:8px">⬆️ 上传 / 替换版本交付物（上传即执行 Fail-Fast 包结构安检）</div>
                <form method="POST" action="{{ route('shop.dev.versions.upload', $version->id) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="row">
                        <div>
                            <label style="margin-top:4px">载体类型</label>
                            <select name="carrier_type" required>
                                @foreach (\Webkul\Shop\Models\SkillAsset::CARRIER_TYPES as $type)
                                    <option value="{{ $type }}" @selected($type === 'file')>
                                        {{ \Webkul\Shop\Models\SkillAsset::CARRIER_LABELS[$type] ?? $type }}（{{ $type }}）
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="margin-top:4px">标准技能包（.skill / .zip，必须包含根级 manifest.json）</label>
                            <input type="file" name="file" accept=".skill,.zip">
                        </div>
                    </div>
                    <div class="row" style="margin-top:8px">
                        <div>
                            <label for="entrypoint_{{ $version->id }}" style="margin-top:4px">启动入口 entrypoint（可选，默认从 manifest.json 自动提取）</label>
                            <input id="entrypoint_{{ $version->id }}" type="text" name="entrypoint" placeholder="如：runtime/main.py">
                        </div>
                        <div>
                            <label for="payload_json_{{ $version->id }}" style="margin-top:4px">载荷 JSON（仅非文件类载体，如 cloud_config）</label>
                            <input id="payload_json_{{ $version->id }}" type="text" name="payload_json" placeholder='{"key":"value"}'>
                        </div>
                    </div>
                    <p class="muted" style="margin-top:8px;margin-bottom:8px">
                        上传规范：包内必须包含根级 <code>manifest.json</code> 声明清单；文件类载体将在上传时执行即时安检（Fail-Fast）。平台不做自动编译，多机型适配需自行产出后登记。
                        <span style="margin-left:8px">
                            <a href="{{ route('shop.dev.versions.sample', ['versionId' => $version->id, 'kind' => 'motion']) }}">下载动作类模板</a> ·
                            <a href="{{ route('shop.dev.versions.sample', ['versionId' => $version->id, 'kind' => 'app']) }}">下载应用类模板</a>
                        </span>
                    </p>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <button type="submit" class="btn small">上传</button>
                    </div>
                </form>
            </div>

            {{-- 四级闭环：适配证据台账（真机 / 仿真 / 静态 / 人工） --}}
            <div style="margin-top:18px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <h3 style="font-size:14px;margin:0">🧪 机型适配验证台账（Compatibility Evidence）</h3>
                    <button type="button" class="btn ghost small" onclick="openDevModal('dev-modal-evidence-{{ $version->id }}')">＋ 登记验证记录</button>
                </div>
                @if ($version->compatibilityEvidence && $version->compatibilityEvidence->isNotEmpty())
                    <table>
                        <thead>
                            <tr>
                                <th>验证机型</th>
                                <th>固件 / SDK</th>
                                <th>验证来源</th>
                                <th>验证阶段</th>
                                <th>结论</th>
                                <th>产物快照一致性</th>
                                <th>验证摘要 / 时间</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($version->compatibilityEvidence as $ev)
                                <tr>
                                    <td><span class="code-chip">{{ $ev->model_code }}</span></td>
                                    <td class="muted">
                                        {{ $ev->firmware_version ?: '—' }}
                                        @if ($ev->sdk_version) / SDK {{ $ev->sdk_version }} @endif
                                    </td>
                                    <td><span class="badge info">{{ $ev->sourceLabel() }}</span></td>
                                    <td>{{ $ev->evidenceTypeLabel() }}</td>
                                    <td>
                                        <span class="badge {{ $ev->result === 'match' ? 'ok' : ($ev->result === 'mismatch' ? 'danger' : 'warn') }}">
                                            {{ $ev->resultLabel() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($ev->asset_hash && $currentAssetHash)
                                            @if ($ev->asset_hash === $currentAssetHash)
                                                <span class="badge ok" title="{{ $ev->asset_hash }}">指纹一致 ({{ substr($ev->asset_hash, 0, 8) }}…)</span>
                                            @else
                                                <span class="badge warn" title="证据指纹 {{ $ev->asset_hash }} ≠ 当前产物 {{ $currentAssetHash }}">产物已变更（旧包证据）</span>
                                            @endif
                                        @elseif ($ev->asset_hash)
                                            <span class="code-chip">{{ substr($ev->asset_hash, 0, 10) }}…</span>
                                        @else
                                            <span class="muted">未绑产物哈希</span>
                                        @endif
                                    </td>
                                    <td class="muted">
                                        {{ $ev->summary ?: '—' }}
                                        <div>{{ optional($ev->observed_at)->format('Y-m-d H:i') }}</div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="muted" style="margin:4px 0 0">尚未登记适配验证记录。可点击「＋ 登记验证记录」录入仿真、静态或真机测试结果。</p>
                @endif
            </div>

            {{--
                发布闸门 —— 回答开发者真正的问题："还差什么才能上架"。
                内容全部来自 `ReviewGate::evaluate()`，此处只做呈现，不重算。
                ⚠ 未接入的层显示"未接入"，不是"通过"：没审 ≠ 通过。
            --}}
            @php($gate = $gates[$version->id] ?? null)
            @if ($gate)
                <h3 style="font-size:14px;margin:18px 0 8px">🛡️ 发布闸门</h3>
                <table>
                    <tr><th style="width:180px">审核层</th><th style="width:140px">结论</th><th>说明</th></tr>
                    {{--
                        主体资格（任务卡 D-5）—— 上架的前置之一，与逐层审核**并列**展示。
                        结论与下一步都取自 SkillPublisher::ownerQualification()（唯一实现），
                        门户只做呈现，不重算 —— 让开发者**事前**就看得见"还差实名/协议"这道坎。
                    --}}
                    @if ($ownerQualification)
                        <tr>
                            <td>主体资格<span class="muted">（实名 / 协议）</span></td>
                            <td>
                                @if ($ownerQualification['ok'])
                                    <span class="badge ok">已通过</span>
                                @elseif ($ownerQualification['code'] === \Webkul\Shop\Services\SkillAudit\SkillPublisher::ERR_OWNER_NOT_VERIFIED)
                                    <span class="badge warn">未通过（未实名）</span>
                                @elseif ($ownerQualification['code'] === \Webkul\Shop\Services\SkillAudit\SkillPublisher::ERR_AGREEMENT_REQUIRED)
                                    <span class="badge warn">未通过（未签协议）</span>
                                @else
                                    <span class="badge danger">未通过（归属异常）</span>
                                @endif
                            </td>
                            <td class="muted">{{ $ownerQualification['message'] }}</td>
                        </tr>
                    @endif
                    @foreach ($gate['layers'] as $state)
                        <tr>
                            <td>
                                {{ $state['label'] }}
                                @unless ($state['required'])
                                    <span class="muted">（不参与闸门）</span>
                                @endunless
                            </td>
                            <td>
                                @if (! $state['available'])
                                    <span class="badge">未接入</span>
                                @elseif ($state['result'] === \Webkul\Shop\Models\AuditRecord::RESULT_PASS)
                                    <span class="badge ok">通过</span>
                                @else
                                    <span class="badge warn">{{ $state['result_label'] }}</span>
                                @endif
                            </td>
                            <td class="muted">
                                {{ $state['reason_detail'] ?: ($state['reason_category'] ?: '—') }}
                                @if ($state['suggestion'])
                                    <div>建议：{{ $state['suggestion'] }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
                @if ($gate['ok'])
                    {{--
                        R1（任务卡 S-3a）：审核全过 ≠ 已经上架 —— 上架权归运营（2026-10-16 拍板）。
                        这里回答开发者真正的问题「我现在在等什么」。
                        ⚠ 刻意**不渲染** $gate['message']：闸门 ok 时它的措辞是"…可以发布。"，
                          会被读成"马上能上架"，与新的"等待平台上架"口径自相矛盾。
                    --}}
                    <p class="muted" style="margin-top:10px">
                        @if ($product->status)
                            <span class="badge ok">审核已通过（该技能已上架）</span>
                        @else
                            <span class="badge ok">审核已通过，等待平台上架</span>
                        @endif
                    </p>

                    @php($requestedAt = $listingRequestedAt[$version->id] ?? null)

                    @if (! $product->status)
                        <p class="muted" style="margin-top:4px">
                            <b>你现在在等什么：</b>审核已经全部通过 —— 但<b>上架由平台运营完成</b>
                            （上架代表平台承担交易与合规责任，开发者不能自助上架）。
                            点「申请上架」把"这个版本已经可以上架了"交给运营，之后就无需再做别的。
                        </p>

                        @if ($requestedAt)
                            <p class="muted" style="margin-top:8px">
                                ✅ 你已于 <b>{{ $requestedAt }}</b> 申请上架，平台运营会尽快处理。
                            </p>
                        @else
                            <form method="POST" action="{{ route('shop.dev.skills.request-listing', $product->id) }}" style="margin-top:10px">
                                @csrf
                                <input type="hidden" name="version_id" value="{{ $version->id }}">
                                <button type="submit" class="btn success">申请上架</button>
                                <span class="muted" style="margin-left:8px">审核已通过，等待平台上架 —— 点击后由运营完成上架。</span>
                            </form>
                        @endif
                    @else
                        <p class="muted" style="margin-top:4px">
                            <span class="badge ok">已上架</span> 用户现在可以买到该技能。
                        </p>
                    @endif
                @else
                    <p class="muted" style="margin-top:10px">
                        <span class="badge warn">还不能发布</span>
                        {{ $gate['message'] }}
                    </p>
                @endif
                {{-- 审核层全过 ≠ 一定能上架：主体资格是**另一道**前置，这里明说，别让"审核已通过"被误读成"已经能上架" --}}
                @if ($ownerQualification && ! $ownerQualification['ok'])
                    <p class="muted" style="margin-top:4px">
                        ⚠ 主体资格未通过 —— <b>即使审核层全部通过，也不能上架</b>；请先按上一行的提示处理主体资格问题。
                    </p>
                @endif
            @endif

            {{-- 本版本历史审核记录内联折叠区 --}}
            @if ($versionAudits->isNotEmpty())
                <details style="margin-top:12px">
                    <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-secondary)">
                        📜 查看本版本历次审核记录（共 {{ $versionAudits->count() }} 条）
                    </summary>
                    <table style="margin-top:8px">
                        <tr><th style="width:90px">层级</th><th style="width:90px">结论</th><th>原因与建议</th><th style="width:160px">时间</th></tr>
                        @foreach ($versionAudits as $ar)
                            <tr>
                                <td><span class="badge">L{{ $ar->layer }}</span></td>
                                <td>
                                    <span class="badge {{ $ar->result === \Webkul\Shop\Models\AuditRecord::RESULT_PASS ? 'ok' : 'danger' }}">
                                        {{ $ar->result === \Webkul\Shop\Models\AuditRecord::RESULT_PASS ? '通过' : '驳回' }}
                                    </span>
                                </td>
                                <td class="muted">
                                    {{ $ar->reason_detail ? ($ar->reason_category.'：'.$ar->reason_detail) : '—' }}
                                    @if ($ar->suggestion)
                                        <div>建议：{{ $ar->suggestion }}</div>
                                    @endif
                                </td>
                                <td class="muted">{{ optional($ar->created_at)->toDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </table>
                </details>
            @endif

            {{-- 提审与撤回操作栏 --}}
            <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border-subtle);display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                <form method="POST" action="{{ route('shop.dev.versions.submit', $version->id) }}" style="margin:0;display:inline-flex;align-items:center;gap:10px">
                    @csrf
                    <button type="submit">提交审核</button>
                    <span class="muted">提交后走 L0 机审；驳回理由见「审核状态」页。</span>
                </form>

                {{-- 撤回提审：仅「审核中」的版本可撤回（其余状态没有"待审"可撤） --}}
                @if ($version->status === \Webkul\Shop\Models\SkillVersion::STATUS_IN_REVIEW)
                    <form method="POST" action="{{ route('shop.dev.versions.withdraw', $version->id) }}" style="margin:0;display:inline-flex;align-items:center;gap:8px">
                        @csrf
                        <button type="submit" class="btn small ghost">撤回提审</button>
                        <span class="muted">撤回后版本回到草稿，自动离开审核队列。</span>
                    </form>
                @endif
            </div>
        </div>

        {{-- 弹窗 1：编辑草稿/被驳回版本的配置与说明 --}}
        @if ($isEditableVersion)
            <div class="dev-modal-backdrop" id="dev-modal-edit-version-{{ $version->id }}">
                <div class="dev-modal">
                    <div class="dev-modal-head">
                        <h3>⚙️ 编辑版本 {{ $version->version }} 配置与安装指引</h3>
                        <button type="button" class="dev-modal-close" onclick="closeDevModal('dev-modal-edit-version-{{ $version->id }}')">×</button>
                    </div>
                    <form method="POST" action="{{ route('shop.dev.versions.update', $version->id) }}">
                        @csrf
                        @method('PUT')
                        <div class="dev-modal-body">
                            <div class="row">
                                <div>
                                    <label>发布通道</label>
                                    <select name="channel">
                                        @foreach (\Webkul\Shop\Models\SkillVersion::CHANNELS as $ch)
                                            <option value="{{ $ch }}" @selected($version->channel === $ch)>
                                                {{ \Webkul\Shop\Models\SkillVersion::CHANNEL_LABELS[$ch] ?? $ch }} ({{ $ch }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label>授权模式</label>
                                    <select name="license_mode">
                                        @foreach (\Webkul\Shop\Models\SkillVersion::LICENSE_MODES as $lm)
                                            <option value="{{ $lm }}" @selected($version->license_mode === $lm)>
                                                {{ $licenseLabels[$lm] ?? $lm }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label>安全风险等级</label>
                                    <select name="risk_level">
                                        @foreach (['low' => '低风险 (low)', 'medium' => '中风险 (medium)', 'high' => '高风险 (high)'] as $rk => $rv)
                                            <option value="{{ $rk }}" @selected($version->risk_level === $rk)>{{ $rv }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="row">
                                <div>
                                    <label>最大激活设备数</label>
                                    <input type="number" name="max_activations" min="1" max="100000" value="{{ $version->max_activations }}">
                                </div>
                                <div>
                                    <label>授权有效天数</label>
                                    <input type="number" name="valid_days" min="1" max="36500" value="{{ $version->valid_days }}">
                                </div>
                                <div>
                                    <label>最低固件/系统版本</label>
                                    <input type="text" name="min_robot_version" maxlength="64" value="{{ $version->min_robot_version }}">
                                </div>
                            </div>

                            <label>声明适配机型（逗号分隔）</label>
                            <input type="text" name="target_models" maxlength="500" value="{{ implode(', ', $compatModels) }}" placeholder="如：Unitree-G1, AgiBot-A2">

                            <label>支持的交付通道</label>
                            <div style="display:flex;gap:16px;padding:6px 0;flex-wrap:wrap">
                                @foreach (\Webkul\Shop\Models\SkillAsset::DELIVERY_CHANNELS as $dc)
                                    <label style="display:inline-flex;align-items:center;gap:6px;margin:0;font-weight:500">
                                        <input type="checkbox" name="delivery_channels[]" value="{{ $dc }}"
                                               @checked(in_array($dc, (array) ($version->delivery_channels ?? ['manual']), true)) style="width:auto">
                                        <span>{{ $dc === 'agent' ? 'Agent 在线下发 (agent)' : '下载 + 人工安装 (manual)' }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <label>声明式硬件权限</label>
                            <div style="display:flex;gap:14px;padding:6px 0;flex-wrap:wrap">
                                @foreach (\Webkul\Shop\Services\SkillPackage\SkillManifest::PERMISSION_LABELS as $permCode => $permLabel)
                                    <label style="display:inline-flex;align-items:center;gap:5px;margin:0;font-weight:500">
                                        <input type="checkbox" name="permissions[]" value="{{ $permCode }}"
                                               @checked(in_array($permCode, (array) ($version->permissions ?? []), true)) style="width:auto">
                                        <span>{{ $permLabel }} ({{ $permCode }})</span>
                                    </label>
                                @endforeach
                            </div>

                            <label>变更说明</label>
                            <input type="text" name="changelog" maxlength="2000" value="{{ $version->changelog }}">

                            <label>人工安装指引（买家选择人工通道时展示）</label>
                            <textarea name="install_guide" rows="3" maxlength="5000">{{ $version->install_guide }}</textarea>
                        </div>
                        <div class="dev-modal-foot">
                            <button type="button" class="btn ghost" onclick="closeDevModal('dev-modal-edit-version-{{ $version->id }}')">取消</button>
                            <button type="submit">保存版本配置</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        {{-- 弹窗 2：登记版本适配验证证据（四级闭环） --}}
        <div class="dev-modal-backdrop" id="dev-modal-evidence-{{ $version->id }}">
            <div class="dev-modal">
                <div class="dev-modal-head">
                    <h3>🧪 登记版本 {{ $version->version }} 适配验证记录</h3>
                    <button type="button" class="dev-modal-close" onclick="closeDevModal('dev-modal-evidence-{{ $version->id }}')">×</button>
                </div>
                <form method="POST" action="{{ route('shop.dev.versions.evidence.store', $version->id) }}">
                    @csrf
                    <div class="dev-modal-body">
                        <p class="muted" style="margin-top:0">
                            本记录将自动绑定当前版本的默认交付物快照（SHA-256 指纹：<code>{{ $currentAssetHash ? substr($currentAssetHash, 0, 16).'…' : '待上传包后绑定' }}</code>）。
                        </p>
                        <div class="row">
                            <div>
                                <label>目标验证机型代码 <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="model_code" required maxlength="64" placeholder="如：Unitree-G1 / AgiBot-A2">
                            </div>
                            <div>
                                <label>验证来源 <span style="color:var(--danger)">*</span></label>
                                <select name="source" required>
                                    @foreach (\Webkul\Shop\Models\SkillCompatibilityEvidence::SOURCE_LABELS as $sk => $sl)
                                        <option value="{{ $sk }}" @selected($sk === 'simulation')>{{ $sl }} ({{ $sk }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div>
                                <label>验证阶段 <span style="color:var(--danger)">*</span></label>
                                <select name="evidence_type" required>
                                    @foreach (\Webkul\Shop\Models\SkillCompatibilityEvidence::EVIDENCE_TYPE_LABELS as $ek => $el)
                                        <option value="{{ $ek }}" @selected($ek === 'runtime')>{{ $el }} ({{ $ek }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>验证结论 <span style="color:var(--danger)">*</span></label>
                                <select name="result" required>
                                    @foreach (\Webkul\Shop\Models\SkillCompatibilityEvidence::RESULT_LABELS as $rk => $rl)
                                        <option value="{{ $rk }}" @selected($rk === 'match')>{{ $rl }} ({{ $rk }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div>
                                <label>固件版本（可选）</label>
                                <input type="text" name="firmware_version" maxlength="64" placeholder="如：2.1.4">
                            </div>
                            <div>
                                <label>SDK 版本（可选）</label>
                                <input type="text" name="sdk_version" maxlength="64" placeholder="如：1.4.0">
                            </div>
                            <div>
                                <label>设备 DID / 仿真环境编号（可选）</label>
                                <input type="text" name="device_did" maxlength="128" placeholder="如：SIM-G1-001">
                            </div>
                        </div>

                        <label>验证摘要说明（可选）</label>
                        <textarea name="summary" rows="2" maxlength="1000" placeholder="记录关键帧率、关节温度、限位表现或已知限制"></textarea>
                    </div>
                    <div class="dev-modal-foot">
                        <button type="button" class="btn ghost" onclick="closeDevModal('dev-modal-evidence-{{ $version->id }}')">取消</button>
                        <button type="submit">保存验证记录</button>
                    </div>
                </form>
            </div>
        </div>
    @empty
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <p class="muted">还没有版本。先在上方创建一个版本，再上传技能包。</p>
            </div>
        </div>
    @endforelse

    {{-- 顶部快捷新建版本弹窗 --}}
    <div class="dev-modal-backdrop" id="dev-modal-create-version">
        <div class="dev-modal">
            <div class="dev-modal-head">
                <h3>＋ 新建技能版本</h3>
                <button type="button" class="dev-modal-close" onclick="closeDevModal('dev-modal-create-version')">×</button>
            </div>
            <form method="POST" action="{{ route('shop.dev.skills.versions.store', $product->id) }}">
                @csrf
                <div class="dev-modal-body">
                    <div class="row">
                        <div>
                            <label>版本号 <span style="color:var(--danger)">*</span></label>
                            <input type="text" name="version" placeholder="1.0.0" required maxlength="32">
                        </div>
                        <div>
                            <label>发布通道</label>
                            <select name="channel">
                                @foreach (\Webkul\Shop\Models\SkillVersion::CHANNELS as $ch)
                                    <option value="{{ $ch }}">{{ \Webkul\Shop\Models\SkillVersion::CHANNEL_LABELS[$ch] ?? $ch }} ({{ $ch }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label>授权模式</label>
                            <select name="license_mode">
                                @foreach (\Webkul\Shop\Models\SkillVersion::LICENSE_MODES as $lm)
                                    <option value="{{ $lm }}">{{ $licenseLabels[$lm] ?? $lm }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <label>变更说明</label>
                    <input type="text" name="changelog" maxlength="2000" placeholder="本版本更新要点">
                    <label>人工安装指引（可选）</label>
                    <textarea name="install_guide" rows="3" maxlength="5000" placeholder="适用于 manual 交付通道的安装步骤说明"></textarea>
                </div>
                <div class="dev-modal-foot">
                    <button type="button" class="btn ghost" onclick="closeDevModal('dev-modal-create-version')">取消</button>
                    <button type="submit">创建版本</button>
                </div>
            </form>
        </div>
    </div>
@endsection
