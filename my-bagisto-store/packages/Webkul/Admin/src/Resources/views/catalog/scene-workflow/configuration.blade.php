<x-admin::layouts>
    <x-slot:title>服务政策与工作日历</x-slot>
    <div class="flex flex-col gap-6"><a href="{{ route('admin.catalog.scene-workflow.index') }}">返回工作台</a><h1 class="text-xl font-bold">服务政策与工作日历</h1>
    <p>以下为起草建议，保存不会生效。须补充真实主体、联系渠道、受理和升级负责人，由另一人批准。工作日历须按实际地区补齐节假日；新版本不改变旧需求已冻结时限。</p>
    @if ($errors->any())<div role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if (bouncer()->hasPermission('catalog.scene-workflow.policy_write'))
    @foreach ($defaults as $kind=>$draft)
    <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">{{ ['commercial'=>'商务条款','retention'=>'私有资料保留','service_calendar'=>'客服工作日历'][$kind] }}草案</h2>
    <form method="POST" action="{{ route('admin.catalog.scene-workflow.policy-draft',$kind) }}" class="flex flex-col gap-3">@csrf
        @if ($kind==='commercial')
        <label>实际签约服务主体<input name="supplier_name" maxlength="255" class="w-full rounded border p-2"></label><label>正式服务联系渠道<input name="supplier_contact" maxlength="255" class="w-full rounded border p-2"></label>
        <label>条款全文<textarea name="text" maxlength="30000" rows="18" class="w-full rounded border p-2" required>{{ $draft['text'] }}</textarea></label>
        <label>必需费用编码（每行一个，可增删，须与正式价目一致）<textarea name="required_price_codes_text" rows="5" class="w-full rounded border p-2" required>{{ implode("\n",$draft['required_price_codes']) }}</textarea></label><p>不适用的费用应在范围和价目中明确处理。</p>
        <label>取消政策（可选 JSON，须按实际商务约定填写）<textarea name="cancellation_json" maxlength="10000" rows="5" class="w-full rounded border p-2"></textarea></label><p>tiers 为时间档位列表：hours_before 表示距开场小时数，penalty_bps 为万分比费率（100 表示 1%）；default_penalty_bps 须明确填写。扣罚基数为已复核总收款。保存后仍须另一人批准，新报价将冻结该版本；留空时不能自动取消结算，系统不会补造默认费率。</p>
        @elseif ($kind==='retention')
        <label>建议保留天数<input type="number" name="days" min="1" max="3650" value="{{ $draft['days'] }}" required></label>
        @foreach (['purpose'=>'必要处理用途与期限依据','deletion_notice'=>'删除、保全和权利联系说明'] as $key=>$label)<label>{{ $label }}<textarea name="{{ $key }}" maxlength="2000" rows="4" class="w-full rounded border p-2" required>{{ $draft[$key] }}</textarea></label>@endforeach
        @else
        <label>IANA 时区<input name="timezone" value="{{ $draft['timezone'] }}" required></label><p>每周工作日</p><div>@foreach ([1=>'一',2=>'二',3=>'三',4=>'四',5=>'五',6=>'六',7=>'日'] as $day=>$label)<label class="mr-3"><input type="checkbox" name="weekdays[]" value="{{ $day }}" @checked($day<=5)>星期{{ $label }}</label>@endforeach</div>
        @foreach ($draft['weekly'][1] as $slot)<div><label>开始<input type="time" name="intervals[{{ $loop->index }}][start]" value="{{ $slot['start'] }}" required></label><label>结束<input type="time" name="intervals[{{ $loop->index }}][end]" value="{{ $slot['end'] }}" required></label></div>@endforeach
        <label>休息日期（每行 YYYY-MM-DD）<textarea name="holiday_dates" rows="6" class="w-full rounded border p-2"></textarea></label>
        <label>特殊工作日期及时间（每行 YYYY-MM-DD HH:mm-HH:mm，可用于调休；单独日期表示停工）<textarea name="override_lines" rows="4" class="w-full rounded border p-2"></textarea></label>
        @foreach (['first_response_minutes'=>'首次实际联系工作分钟','escalation_minutes'=>'升级工作分钟'] as $key=>$label)<label>{{ $label }}<input type="number" min="1" max="20160" name="{{ $key }}" value="{{ $draft[$key] }}" required></label>@endforeach
        @foreach (['owner_id'=>'首次受理负责人','escalation_owner_id'=>'升级负责人（须另一人）'] as $key=>$label)<label>{{ $label }}<select name="{{ $key }}" required><option value="">请选择真实负责人</option>@foreach ($admins as $admin)<option value="{{ $admin->id }}">{{ $admin->name }}</option>@endforeach</select></label>@endforeach
        @endif<button class="primary-button self-start">保存新版本，等待独立审核</button>
    </form></section>@endforeach @endif
    <section class="rounded border p-4"><h2 class="text-lg font-semibold">不可覆盖的版本记录</h2>@forelse ($policies as $policy)
    <details class="mt-4 border-t pt-3"><summary>{{ $policy->kind }} v{{ $policy->version }} · {{ $policy->state }} · #{{ $policy->id }}</summary><pre class="whitespace-pre-wrap">{{ json_encode(\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($policy->content),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre><p>{{ $policy->decision_note }}</p>
    @if (bouncer()->hasPermission('catalog.scene-workflow.policy_review'))@foreach ($policy->state==='draft'?['approve'=>'批准生效','reject'=>'退回重编']:($policy->state==='approved'?['revoke'=>'撤销，不恢复旧版']:[]) as $action=>$label)
    <form method="POST" action="{{ route('admin.catalog.scene-workflow.policy-decision',['id'=>$policy->id,'action'=>$action]) }}" class="mt-3">@csrf<input type="hidden" name="row_version" value="{{ $policy->row_version }}"><label>核验与决定依据<input name="reason" minlength="3" maxlength="2000" required></label><button class="secondary-button">{{ $label }}</button></form>@endforeach @endif</details>
    @empty<p>尚无政策版本，默认模板尚未保存。</p>@endforelse</section></div>
</x-admin::layouts>
