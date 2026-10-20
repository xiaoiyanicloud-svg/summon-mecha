<x-admin::layouts>
    <x-slot:title>需求 {{ $record->request_no }}</x-slot>
    <div class="flex flex-col gap-6">
        <a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.index') }}">返回工作台</a>
        <h1 class="text-xl font-bold">{{ $record->request_no }} · {{ $record->state }}</h1>
        <p>需求修订 {{ $record->revision_no }} · 当前版本 {{ $record->row_version }} · 负责人 {{ $record->assignee_id ?: '待分派' }}</p>
        @if ($errors->any())<div role="alert" class="rounded border border-red-400 p-4">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">客户需求与现场事实</h2><p class="whitespace-pre-wrap">{{ $revision->original_text }}</p>
            @foreach ($schema['fields'] as $field)<p class="mt-2">{{ $field['label'] }}：{{ array_key_exists($field['key'],$facts) && $facts[$field['key']] !== null ? (is_bool($facts[$field['key']]) ? ($facts[$field['key']] ? '是' : '否') : $facts[$field['key']]) : '待补充' }}</p>@endforeach
            @if (bouncer()->hasPermission('catalog.scene-workflow.contact'))<button type="button" id="load-scene-contact" class="secondary-button mt-4">读取联系方式并留痕</button><p id="scene-contact" class="mt-2"></p>@endif
        </section>
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">私有现场资料与履约证据</h2>
            @forelse ($attachments as $attachment)<p><a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.attachment',['id'=>$record->id,'attachmentId'=>$attachment->id]) }}">附件 #{{ $attachment->id }} · {{ $attachment->mime }} · {{ $attachment->size }} 字节</a></p>@empty<p>暂无扫描通过的私有资料</p>@endforelse
            @if (bouncer()->hasPermission('catalog.scene-workflow.requests'))<form method="POST" enctype="multipart/form-data" action="{{ route('admin.catalog.scene-workflow.upload',$record->id) }}" class="mt-3 flex flex-col gap-3">@csrf<label>代录资料（明确记录为平台人员上传）<input type="file" name="file" accept="application/pdf,image/png,image/jpeg" required></label><p>最多 8 MB，必须通过已配置的安全扫描；未配置扫描器时不会落入可用资料。</p><button class="secondary-button self-start">扫描并上传私有资料</button></form>@endif
        </section>
        @if (bouncer()->hasPermission('catalog.scene-workflow.requests'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">受理与联系</h2>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.request',['id'=>$record->id,'action'=>'assign']) }}" class="mb-4 flex gap-3">@csrf<input type="hidden" name="row_version" value="{{ $record->row_version }}"><select name="assignee_id" class="rounded border p-2" required>@foreach ($admins as $admin)<option value="{{ $admin->id }}">{{ $admin->name }}</option>@endforeach</select><button class="primary-button">分派 / 转派</button></form>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.request',['id'=>$record->id,'action'=>'contact']) }}" class="flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $record->row_version }}"><label>实际联系时间<input name="occurred_at" placeholder="2026-10-20T15:00:00+08:00" class="w-full rounded border p-2" required></label><select name="channel" class="rounded border p-2"><option value="phone">电话</option><option value="wechat">微信</option><option value="email">邮件</option><option value="in_person">现场</option></select><label>联系事实记录<textarea name="note" maxlength="5000" class="w-full rounded border p-2" required></textarea></label><button class="primary-button self-start">记录已发生的联系</button></form>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.request',['id'=>$record->id,'action'=>'close']) }}" class="mt-4 flex gap-3">@csrf<input type="hidden" name="row_version" value="{{ $record->row_version }}"><input name="note" placeholder="关闭原因" class="rounded border p-2" required><button class="secondary-button">关闭需求</button></form>
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.assess'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">现场与任务能力评估</h2>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.assess',$record->id) }}" class="flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $record->row_version }}"><div data-repeat-container="candidates"><div data-repeat-row class="grid gap-2 md:grid-cols-4"><input name="candidates[0][product_id]" placeholder="真实商品 ID" class="rounded border p-2" required><input name="candidates[0][binding_id]" placeholder="binding ID" class="rounded border p-2"><input name="candidates[0][capability]" placeholder="任务能力编码" class="rounded border p-2" required><input name="candidates[0][evidence_id]" placeholder="核验证据 ID" class="rounded border p-2"></div></div><button type="button" class="secondary-button self-start" data-add-row="candidates">增加候选设备</button><button class="primary-button self-start">执行确定性评估</button></form>
            @foreach ($assessments as $assessment)<div class="mt-4 border-t pt-3"><p>评估 #{{ $assessment->id }} · {{ $assessment->state }} · 需求修订 {{ $assessment->request_revision_no }}</p>@foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($assessment->checks) as $check)<p>{{ $check['key'] }}：{{ $check['state'] }} — {{ $check['reason'] }}</p>@endforeach</div>@endforeach
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.quote'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">编制报价</h2><p>选择服务端价目版本；缺少正式商务条款、必需费用或通过的评估将被拒绝。</p>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.quote',$record->id) }}" class="mt-3 flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $record->row_version }}">
                <label>评估<select name="assessment_id" class="w-full rounded border p-2" required>@foreach ($assessments as $assessment)<option value="{{ $assessment->id }}">#{{ $assessment->id }} · {{ $assessment->state }}</option>@endforeach</select></label>
                <div data-repeat-container="items"><div data-repeat-row class="grid gap-2 md:grid-cols-3"><select name="items[0][price_item_id]" class="rounded border p-2" required>@foreach ($prices as $price)<option value="{{ $price->id }}">{{ $price->name }} v{{ $price->version }} / {{ $price->unit_minor }} 分 / {{ $price->unit }}</option>@endforeach</select><input name="items[0][quantity]" type="number" min="1" placeholder="数量" class="rounded border p-2" required><input name="items[0][billing_units]" type="number" min="1" placeholder="计费单位数" class="rounded border p-2" required></div></div><button type="button" class="secondary-button self-start" data-add-row="items">增加费用项</button>
                <label>折扣（万分比）<input name="discount_bps" type="number" min="0" max="10000" value="0" class="w-full rounded border p-2" required></label>
                <label>报价有效期（含时区）<input name="expires_at" class="w-full rounded border p-2" required></label>
                @foreach (['starts_at'=>'服务开始','ends_at'=>'服务结束','timezone'=>'服务地 IANA 时区'] as $key=>$label)<label>{{ $label }}<input name="schedule[{{ $key }}]" value="{{ $facts[$key] ?? '' }}" class="w-full rounded border p-2" required></label>@endforeach
                <label>准备运输缓冲（分钟）<input name="schedule[buffer_before_minutes]" type="number" min="0" class="w-full rounded border p-2" required></label><label>返程维护缓冲（分钟）<input name="schedule[buffer_after_minutes]" type="number" min="0" class="w-full rounded border p-2" required></label><button class="primary-button self-start">生成新报价版本</button>
            </form>
        </section>@endif
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">报价与资源</h2>
            @forelse ($quotes as $quote)<div class="mt-3 rounded border p-3"><p>报价 #{{ $quote->id }} / v{{ $quote->version }} · {{ $quote->state }} · 总额 {{ number_format($quote->total_minor/100,2) }} {{ $quote->currency }} · 押金 {{ number_format($quote->deposit_minor/100,2) }}</p><p>有效期 {{ $quote->expires_at }}</p>
                @foreach (['draft'=>'review','in_review'=>'approve','approved'=>'send','sent'=>'void'] as $state=>$action)
                    @if ($quote->state === $state && bouncer()->hasPermission('catalog.scene-workflow.'.($action==='approve'?'approve':'quote')))<form method="POST" action="{{ route('admin.catalog.scene-workflow.quote-action',['id'=>$quote->id,'action'=>$action]) }}" class="mt-2">@csrf<input type="hidden" name="row_version" value="{{ $quote->row_version }}">@if ($action === 'void')<label>作废原因<input name="reason" minlength="3" maxlength="2000" class="rounded border p-2" required></label>@endif<button class="secondary-button">{{ ['review'=>'送审','approve'=>'批准','send'=>'发送给客户','void'=>'作废'][$action] }}</button></form>@endif
                @endforeach
                @if ($quote->state === 'in_review' && bouncer()->hasPermission('catalog.scene-workflow.approve'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.quote-action',['id'=>$quote->id,'action'=>'return']) }}" class="mt-3">@csrf<input type="hidden" name="row_version" value="{{ $quote->row_version }}"><label>退回原因<input name="reason" minlength="3" maxlength="2000" class="rounded border p-2" required></label><button class="secondary-button">退回重编（保留本版，须生成新版）</button></form>@endif
                @if (bouncer()->hasPermission('catalog.scene-workflow.quote') || bouncer()->hasPermission('catalog.scene-workflow.approve'))@foreach ($decisions->where('quote_id',$quote->id) as $decision)<p class="mt-2 whitespace-pre-wrap">{{ ['review_returned'=>'审核退回','customer_rejected'=>'客户拒绝','admin_voided'=>'人工作废'][$decision->kind] ?? $decision->kind }} · {{ $decision->created_at }}：{{ $decision->reason }}</p>@endforeach @endif
                @if (in_array($quote->state,['sent','accepted']) && bouncer()->hasPermission('catalog.scene-workflow.reserve'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.hold',$quote->id) }}" class="mt-3 flex flex-col gap-2">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label>逐台资源（可多选）<select name="resource_ids[]" multiple class="w-full rounded border p-2" required>@foreach ($resources as $resource)<option value="{{ $resource->id }}">#{{ $resource->id }} {{ $resource->resource_no }} · {{ $resource->kind }} · {{ $resource->city }}</option>@endforeach</select></label><button class="secondary-button self-start">短时预留资源</button></form>@endif
            </div>@empty<p>尚无报价</p>@endforelse
        </section>
        @if (bouncer()->hasPermission('catalog.scene-workflow.deliver'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">交付任务</h2>
            @forelse ($tasks as $task)<form method="POST" action="{{ route('admin.catalog.scene-workflow.task',['id'=>$task->project_id,'taskId'=>$task->id]) }}" class="mt-3 flex flex-col gap-2 border-t pt-3">@csrf<p>{{ $task->name }} · {{ $task->state }}</p><input type="hidden" name="row_version" value="{{ $task->row_version }}">@foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($task->checklist) as $check)<label><input type="checkbox" name="checks[]" value="{{ $check }}" required>{{ ['scope_confirmed'=>'范围已确认','safety_checked'=>'安全检查已完成','evidence_recorded'=>'实际证据已记录'][$check] ?? $check }}</label>@endforeach<label>实际发生时间（ISO 8601，含 UTC offset）<input placeholder="2026-10-20T15:00:00+08:00" name="occurred_at" class="w-full rounded border p-2" required></label><label>本需求已扫描附件<select name="evidence_ids[]" multiple class="w-full rounded border p-2" required>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">附件 #{{ $attachment->id }} · {{ $attachment->mime }}</option>@endforeach</select></label><button class="primary-button self-start">记录人工完成</button></form>@empty<p>尚无确认项目；不会因报价接受自动显示交付完成。</p>@endforelse
        </section>@endif
        @foreach ($projects as $project)
        @if (bouncer()->hasPermission('catalog.scene-workflow.acceptance_record') || bouncer()->hasPermission('catalog.scene-workflow.acceptance_review'))
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">项目 #{{ $project->id }} · 客户线下验收凭证</h2>
            <p>仅记录客户已经发生的确认。代录不等于验收生效；另一名有复核权限的人员核对客户代表资格、逐项结论和私有原件后才能生效。未回复不能代录为通过。</p>
            @if (in_array($project->state,['awaiting_acceptance','rectification']) && bouncer()->hasPermission('catalog.scene-workflow.acceptance_record'))
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.offline-record',$project->id) }}" class="mt-3 flex flex-col gap-3">
                @csrf<input type="hidden" name="row_version" value="{{ $project->row_version }}"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <label>实际确认人姓名<input name="signatory_name" maxlength="100" class="w-full rounded border p-2" required></label>
                <label>客户代表资格与核验依据<textarea name="authority_basis" minlength="3" maxlength="2000" class="w-full rounded border p-2" required></textarea></label>
                <label>确认方式<select name="medium" class="w-full rounded border p-2"><option value="signed_document">签署文件</option><option value="email">客户邮件</option><option value="in_person_record">现场确认记录</option></select></label>
                <label>客户实际确认时间（ISO 8601，含 UTC offset）<input name="occurred_at" placeholder="2026-10-20T15:00:00+08:00" class="w-full rounded border p-2" required></label>
                @foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($project->snapshot)['acceptance_template'] ?? [] as $item)
                    <fieldset class="rounded border p-3"><legend>{{ $item['label'] }}{{ ($item['required'] ?? true) ? '（必需）' : '' }}</legend>
                        <input type="hidden" name="items[{{ $loop->index }}][key]" value="{{ $item['key'] }}">
                        <select name="items[{{ $loop->index }}][result]" class="rounded border p-2" required><option value="">选择客户原始结论</option><option value="pass">通过</option><option value="fail">未通过</option></select>
                        <label>客户结论说明<textarea name="items[{{ $loop->index }}][note]" maxlength="5000" class="w-full rounded border p-2"></textarea></label>
                    </fieldset>
                @endforeach
                <label>客户确认原件（本需求已扫描私有附件）<select name="evidence_ids[]" multiple class="w-full rounded border p-2" required>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">#{{ $attachment->id }} {{ $attachment->mime }}</option>@endforeach</select></label>
                <label><input type="checkbox" name="authority_confirmed" value="1" required>已核实确认人为本客户或获授权的客户代表，凭证包含本次逐项结论</label>
                <button class="primary-button self-start">提交线下凭证，等待独立复核</button>
            </form>@endif
            @forelse ($offlineConfirmations->where('project_id',$project->id) as $confirmation)
            <div class="mt-4 border-t pt-3"><p>#{{ $confirmation->id }} · {{ $confirmation->state }} · 录入人 #{{ $confirmation->recorded_by }}</p>
                <p>客户确认：{{ $confirmation->occurred_at }} UTC；平台代录：{{ $confirmation->created_at }} UTC</p>
                <p>确认人：{{ $confirmation->attestation['signatory_name'] }}；方式：{{ $confirmation->attestation['medium'] }}</p>
                <p class="whitespace-pre-wrap">代表资格依据：{{ $confirmation->attestation['authority_basis'] }}</p>
                @foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($confirmation->items) as $item)<p>{{ $item['key'] }}：{{ $item['result'] === 'pass' ? '通过' : '未通过' }}；{{ $item['note'] ?? '' }}</p>@endforeach
                @foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($confirmation->evidence) as $proof)<a class="mr-3 text-blue-600" href="{{ route('admin.catalog.scene-workflow.attachment',['id'=>$record->id,'attachmentId'=>$proof['attachment_id']]) }}">核对私有原件 #{{ $proof['attachment_id'] }}</a>@endforeach
                @if ($confirmation->state === 'pending_review' && bouncer()->hasPermission('catalog.scene-workflow.acceptance_review'))
                <form method="POST" action="{{ route('admin.catalog.scene-workflow.offline-review',['id'=>$project->id,'confirmationId'=>$confirmation->id]) }}" class="mt-3 flex flex-col gap-2">
                    @csrf<input type="hidden" name="row_version" value="{{ $project->row_version }}"><input type="hidden" name="confirmation_version" value="{{ $confirmation->row_version }}"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <label>复核结论<select name="decision" class="rounded border p-2" required><option value="">请选择</option><option value="approve">原件、代表资格和逐项结论核对无误</option><option value="reject">退回，保留原始凭证</option></select></label>
                    <label>实际复核说明<textarea name="note" minlength="3" maxlength="2000" class="w-full rounded border p-2" required></textarea></label><button class="secondary-button self-start">记录独立复核</button>
                </form>@else<p>复核人 #{{ $confirmation->reviewed_by }} · {{ $confirmation->reviewed_at }} · {{ $confirmation->review_note }}</p>@endif
            </div>@empty<p class="mt-3">暂无线下确认凭证。</p>@endforelse
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.finance'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">项目 #{{ $project->id }} 线下款项记录</h2><p>录入凭证不会自动改变支付状态；由另一位有复核权限的人员复核银行/线下实际事实。</p>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.commercial',$project->id) }}" class="mt-3 flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $project->row_version }}"><select name="kind" class="rounded border p-2"><option value="payment_receipt">实际线下收款</option><option value="refund_due">经核准应退款</option><option value="refund_receipt">实际线下退款</option></select><label>金额（分）<input name="amount_minor" type="number" min="1" class="w-full rounded border p-2" required></label><label>线下凭证索引<input name="reference" maxlength="255" class="w-full rounded border p-2" required></label><label>证明附件<select name="evidence_ids[]" multiple class="w-full rounded border p-2" required>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">#{{ $attachment->id }} {{ $attachment->mime }}</option>@endforeach</select></label><button class="primary-button self-start">记录待复核凭证</button></form>
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.finance_review'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">款项复核</h2>@foreach ($commercial->where('project_id',$project->id) as $entry)<p>#{{ $entry->id }} · {{ $entry->kind }} · {{ $entry->amount_minor }} 分 · {{ $entry->reference }} · {{ $entry->reviewed_at ? '已人工复核' : '待复核' }}</p>@if (!$entry->reviewed_at)<form method="POST" action="{{ route('admin.catalog.scene-workflow.commercial-review',['id'=>$project->id,'recordId'=>$entry->id]) }}" class="mt-2">@csrf<input type="hidden" name="row_version" value="{{ $project->row_version }}"><button class="secondary-button">确认已核对实际线下凭证</button></form>@endif @endforeach</section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.deliver') && !$project->returned)<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">返库记录</h2><form method="POST" action="{{ route('admin.catalog.scene-workflow.return',$project->id) }}" class="flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $project->row_version }}"><label>实际返库时间（ISO 8601，含 UTC offset）<input placeholder="2026-10-20T18:00:00+08:00" name="occurred_at" class="w-full rounded border p-2" required></label><label><input type="checkbox" name="parts_checked" value="1" required>部件已核对</label><label><input type="checkbox" name="appearance_checked" value="1" required>外观已检查</label><label>是否有损坏争议<select name="damage_dispute" class="rounded border p-2"><option value="0">无争议</option><option value="1">存在争议</option></select></label><select name="evidence_ids[]" multiple class="rounded border p-2" required>@foreach ($attachments as $attachment)<option value="{{ $attachment->id }}">证据 #{{ $attachment->id }}</option>@endforeach</select><button class="primary-button self-start">记录返库，不自动退款或关闭</button></form></section>@endif
        @endforeach
        @if (bouncer()->hasPermission('catalog.scene-workflow.support'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">服务售后处理</h2>@forelse ($tickets as $ticket)<div class="mt-3 border-t pt-3"><p>#{{ $ticket->id }} · {{ $ticket->state }} · {{ $ticket->description }}</p>@if ($ticket->state !== 'resolved')<form method="POST" action="{{ route('admin.catalog.scene-workflow.ticket',$ticket->id) }}" class="mt-2 flex flex-col gap-2">@csrf<input type="hidden" name="row_version" value="{{ $ticket->row_version }}"><textarea name="resolution" maxlength="10000" placeholder="实际处理结果（客户可见）" class="rounded border p-2" required></textarea><button class="primary-button self-start">记录处理完成</button></form>@else<p>{{ $ticket->resolution }}</p>@endif</div>@empty<p>暂无服务售后工单</p>@endforelse</section>@endif
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">处理记录（内部）</h2>@foreach ($activities as $activity)<p class="mt-2">{{ $activity->occurred_at }} · {{ $activity->kind }} · {{ $activity->note }}</p>@endforeach</section>
    </div>
    @push('scripts')<script>
        document.querySelectorAll('[data-add-row]').forEach(function (button) {
            button.addEventListener('click', function () {
                var container = document.querySelector('[data-repeat-container="'+button.dataset.addRow+'"]');
                var count = container.children.length;
                if (count >= 50) return;
                var row = container.firstElementChild.cloneNode(true);
                row.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace('[0]', '['+count+']'); input.value = ''; });
                container.appendChild(row);
            });
        });
        document.getElementById('load-scene-contact')?.addEventListener('click', async function () {
            var output = document.getElementById('scene-contact');
            try {
                var result = await fetch(@json(route('admin.catalog.scene-workflow.contact', $record->id)), {headers:{Accept:'application/json'},cache:'no-store'});
                var body = await result.json();
                if (!result.ok) throw new Error(body.message || '读取失败');
                output.textContent = body.data ? [body.data.name,body.data.phone,body.data.company,body.data.wechat].filter(Boolean).join(' · ') : '无联系方式';
            } catch (error) { output.textContent = error.message; }
        });
    </script>@endpush
</x-admin::layouts>
