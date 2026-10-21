@if ($quote->state === 'accepted')
<div class="mt-4 rounded border p-3"><h3 class="font-semibold">订单确认前置条件</h3><p>商务/资源确认截止 {{ $quote->confirmation_deadline ?: '旧版本未冻结，须协商新版' }} UTC。报价接受、资源临时预留、正式项目与收款分别记录。</p>
    @foreach (($reservations ?? collect())->where('quote_id',$quote->id)->groupBy('hold_group') as $group=>$rows)
        @php($head=$rows->first())
        <p class="mt-2">预留组 {{ $group }} · {{ $rows->count() }} 项 · {{ $head->state }} · 到期 {{ $head->hold_expires_at }} UTC</p>
        @foreach ($rows as $reservation)<p>资源 #{{ $reservation->resource_id }} · 占用 {{ $reservation->occupies_from }} 至 {{ $reservation->occupies_until }} UTC</p>@endforeach
        @if ($head->state === 'held' && bouncer()->hasPermission('catalog.scene-workflow.reserve'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.release-hold',$quote->id) }}" class="mt-2">@csrf<input type="hidden" name="hold_group" value="{{ $group }}"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="secondary-button">释放此临时预留组</button></form>@endif
    @endforeach
    @if (bouncer()->hasPermission('catalog.scene-workflow.finance'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.quote-commercial',$quote->id) }}" class="mt-3 flex flex-col gap-3">@csrf
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <label>实际商务事实<select name="kind" class="w-full rounded border p-2"><option value="offline_contract">已签署线下合同/授信依据</option><option value="offline_receipt">已实际收款的线下凭证</option></select></label>
        <label>实际收款金额（分；合同确认填 0）<input name="amount_minor" type="number" min="0" class="w-full rounded border p-2" required></label>
        <label>唯一凭证索引<input name="reference" minlength="3" maxlength="255" class="w-full rounded border p-2" required></label>
        <label>实际发生时间（含 UTC offset）<input name="occurred_at" class="w-full rounded border p-2" required></label>
        <label>本需求扫描通过的私有原件<select name="evidence_ids[]" multiple class="w-full rounded border p-2" required>@foreach ($attachments as $a)<option value="{{ $a->id }}">#{{ $a->id }} {{ $a->mime }}</option>@endforeach</select></label>
        <button class="secondary-button self-start">登记商务事实，等待独立复核</button>
    </form>@endif
    @foreach (($quoteCommercial??collect())->where('quote_id',$quote->id) as $entry)<div class="mt-3 border-t pt-3"><p>商务记录 #{{ $entry->id }} · {{ $entry->kind }} · {{ $entry->amount_minor }} 分 · {{ $entry->state }} · 录入 #{{ $entry->recorded_by }} / 复核 #{{ $entry->reviewed_by ?: '待复核' }}</p>
        @if ($entry->state === 'pending' && bouncer()->hasPermission('catalog.scene-workflow.finance_review'))<p>凭证索引：{{ \Illuminate\Support\Facades\Crypt::decryptString($entry->encrypted_reference) }}</p>
            @foreach (\Webkul\Shop\Services\Scenes\SceneWorkflow::unpack($entry->evidence) as $proof)<a class="text-blue-600 mr-3" href="{{ route('admin.catalog.scene-workflow.attachment',['id'=>$record->id,'attachmentId'=>$proof['id']]) }}">核对原件 #{{ $proof['id'] }}</a>@endforeach
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.quote-commercial-review',['id'=>$quote->id,'recordId'=>$entry->id]) }}" class="mt-2 flex flex-wrap gap-2">@csrf<select name="decision"><option value="approve">已核对真实合同或银行收款，批准</option><option value="reject">凭证不符，拒绝</option></select><input name="reason" minlength="3" maxlength="2000" placeholder="独立复核依据" class="rounded border p-2" required><button class="secondary-button">提交复核</button></form>
        @endif
    </div>@endforeach
</div>
@endif
