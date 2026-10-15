@extends('shop::dev.layouts.app')

@section('title', $product->name.' · 开发者中心')

@section('content')
    <h1>
        {{ $product->name }}
        <a class="btn ghost small" href="{{ route('shop.dev.skills.edit', $product->id) }}" style="margin-left:10px;vertical-align:middle">编辑信息</a>
    </h1>
    <p class="muted">
        SKU: {{ $product->sku }} ｜ 定价：￥{{ number_format((float) $product->price, 2) }} ｜
        <span class="badge {{ $product->status ? 'ok' : '' }}">{{ $product->status ? '已上架' : '未上架' }}</span>
        <a href="{{ route('shop.dev.skills.index') }}" style="margin-left:10px">← 返回列表</a>
    </p>

    {{-- 下架：已上架的技能才显示。理由必填、会留痕；下架不回收已售授权；无自助重新上架入口。 --}}
    @if ($product->status)
        <div class="card">
            <h2>下架技能</h2>
            <form method="POST" action="{{ route('shop.dev.skills.offline', $product->id) }}">
                @csrf
                <div>
                    <label for="offline_reason">下架理由（必填，会留痕）</label>
                    <input id="offline_reason" type="text" name="reason" required minlength="2" maxlength="500"
                           placeholder="例如：技能存在缺陷，暂时下架整改">
                </div>
                <p class="muted">
                    下架只停<b>新增销售</b>：已购买用户的授权不受影响，设备继续可用。
                    重新上架请联系平台运营，或修改后重新提审 → 发布（开发者不能自助重新上架）。
                </p>
                <div style="margin-top:12px">
                    <button type="submit">下架</button>
                </div>
            </form>
        </div>
    @endif

    {{-- 新建版本 --}}
    <div class="card">
        <h2>新建版本</h2>
        <form method="POST" action="{{ route('shop.dev.skills.versions.store', $product->id) }}">
            @csrf
            <div class="row">
                <div>
                    <label for="version">版本号</label>
                    <input id="version" type="text" name="version" placeholder="1.0.0" required maxlength="32">
                </div>
                <div>
                    <label for="changelog">变更说明</label>
                    <input id="changelog" type="text" name="changelog" maxlength="2000">
                </div>
            </div>
            <div style="margin-top:14px">
                <button type="submit">创建版本</button>
            </div>
        </form>
    </div>

    @forelse ($versions as $version)
        <div class="card">
            <h2>
                版本 {{ $version->version }}
                <span class="badge {{ $version->status === 'published' ? 'ok' : '' }}">{{ $version->status }}</span>
                <span class="muted" style="font-weight:400">通道 {{ $version->channel }}</span>
            </h2>

            {{-- 已上传的交付物 --}}
            @if ($version->assets->isNotEmpty())
                <table>
                    <tr><th>载体</th><th>文件</th><th>大小</th><th>状态</th></tr>
                    @foreach ($version->assets as $asset)
                        <tr>
                            <td>{{ $asset->carrier_type }}</td>
                            <td>{{ $asset->file_name ?: '—' }}</td>
                            <td>{{ $asset->file_size ? number_format($asset->file_size / 1024, 1).' KB' : '—' }}</td>
                            <td>{{ $asset->fileExists() ? '已落盘' : '无文件' }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <p class="muted">还没有交付物。</p>
            @endif

            {{-- 上传技能包 --}}
            <form method="POST" action="{{ route('shop.dev.versions.upload', $version->id) }}" enctype="multipart/form-data" style="margin-top:14px">
                @csrf
                <div class="row">
                    <div>
                        <label>载体类型</label>
                        <select name="carrier_type" required>
                            {{-- 白名单取自模型常量，不在视图里另写一份 --}}
                            @foreach (\Webkul\Shop\Models\SkillAsset::CARRIER_TYPES as $type)
                                <option value="{{ $type }}" @selected($type === 'file')>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>技能包文件（.skill / zip）</label>
                        <input type="file" name="file">
                    </div>
                </div>
                <div style="margin-top:12px">
                    <button type="submit" class="btn small">上传</button>
                </div>
            </form>

            {{--
                发布闸门 —— 回答开发者真正的问题："还差什么才能上架"。
                内容全部来自 `ReviewGate::evaluate()`，此处只做呈现，不重算。
                ⚠ 未接入的层显示"未接入"，不是"通过"：没审 ≠ 通过。
            --}}
            @php($gate = $gates[$version->id] ?? null)
            @if ($gate)
                <h3 style="font-size:14px;margin:16px 0 6px">发布闸门</h3>
                <table>
                    <tr><th style="width:150px">审核层</th><th style="width:110px">结论</th><th>说明</th></tr>
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
                                    <span class="badge">未通过（未实名）</span>
                                @elseif ($ownerQualification['code'] === \Webkul\Shop\Services\SkillAudit\SkillPublisher::ERR_AGREEMENT_REQUIRED)
                                    <span class="badge">未通过（未签协议）</span>
                                @else
                                    <span class="badge">未通过（归属异常）</span>
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
                                    <span class="badge">{{ $state['result_label'] }}</span>
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
                <p class="muted" style="margin-top:8px">
                    @if ($gate['ok'])
                        <span class="badge ok">可以发布</span>
                    @else
                        <span class="badge">还不能发布</span>
                    @endif
                    {{ $gate['message'] }}
                </p>
                {{-- 审核层全过 ≠ 一定能上架：主体资格是**另一道**前置，这里明说，别让"可以发布"被误读成"已经能上架" --}}
                @if ($ownerQualification && ! $ownerQualification['ok'])
                    <p class="muted" style="margin-top:4px">
                        ⚠ 主体资格未通过 —— <b>即使审核层全部通过，也不能上架</b>；请先按上一行的提示处理主体资格问题。
                    </p>
                @endif
            @endif

            {{-- 提审 --}}
            <form method="POST" action="{{ route('shop.dev.versions.submit', $version->id) }}" style="margin-top:14px">
                @csrf
                <button type="submit">提交审核</button>
                <span class="muted">提交后走 L0 机审；驳回理由见「审核状态」页。</span>
            </form>

            {{-- 撤回提审：仅「审核中」的版本可撤回（其余状态没有"待审"可撤） --}}
            @if ($version->status === \Webkul\Shop\Models\SkillVersion::STATUS_IN_REVIEW)
                <form method="POST" action="{{ route('shop.dev.versions.withdraw', $version->id) }}" style="margin-top:10px">
                    @csrf
                    <button type="submit" class="btn small ghost">撤回提审</button>
                    <span class="muted">撤回后版本回到草稿，自动离开审核队列。</span>
                </form>
            @endif
        </div>
    @empty
        <div class="card"><p class="muted">还没有版本。先在上方创建一个版本，再上传技能包。</p></div>
    @endforelse
@endsection
