<x-admin::layouts>
    <x-slot:title>场景资源与交易映射</x-slot>
    <div class="flex flex-col gap-6">
        <a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.index') }}">返回场景工作台</a>
        <h1 class="text-xl font-bold">资源班次核验与交易映射</h1>
        <p>每条台账代表一台设备、一名人员或一件配件。来源、城市、时区与完整可服务班次经另一人核实后才计入可确认资源；系统不会把平台代录当作外部伙伴接受派工。</p>
        @if ($errors->any())<div role="alert" class="rounded border border-red-400 p-4">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @forelse ($resources as $resource)
        <section class="rounded border p-4"><h2 class="text-lg font-semibold">{{ $resource->resource_no }} · {{ $resource->kind }} · {{ $resource->state }}</h2>
            <p>{{ $resource->city }} · {{ $resource->timezone }} · 商品 #{{ $resource->product_id ?: '无' }} · binding #{{ $resource->binding_id ?: '无' }}</p>
            @if (!$resource->created_by && bouncer()->hasPermission('catalog.scene-workflow.supply'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.resource-legacy',$resource->id) }}" class="mt-3 flex flex-col gap-3">@csrf<input type="hidden" name="row_version" value="{{ $resource->row_version }}"><label>历史资源身份索引<input name="identity_reference" minlength="3" maxlength="255" class="w-full rounded border p-2" required></label><label>实际供给来源与补录依据<input name="external_reference" minlength="3" maxlength="255" class="w-full rounded border p-2" required></label><button class="secondary-button self-start">补齐实际来源，转入独立核验</button></form>@endif
            @if (bouncer()->hasPermission('catalog.scene-workflow.supply_review'))
            <p>来源索引：{{ $resource->external_reference }} · 登记人 #{{ $resource->created_by ?: '历史未记录，须重新登记来源' }}</p>
            <p>核验有效期 {{ $resource->verification_expires_at ?: '未核验' }} UTC · 核验人 #{{ $resource->verified_by ?: '待复核' }}</p>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.resource-verify',$resource->id) }}" class="mt-3 flex flex-col gap-3">@csrf
                <input type="hidden" name="row_version" value="{{ $resource->row_version }}">
                <label>本次服务城市<input name="city" value="{{ $resource->city }}" maxlength="100" class="w-full rounded border p-2" required></label>
                <label>本次服务地 IANA 时区<input name="timezone" value="{{ $resource->timezone }}" maxlength="80" class="w-full rounded border p-2" required></label>
                <label>本次独立核验依据<input name="verification_reference" minlength="3" maxlength="255" class="w-full rounded border p-2" required></label>
                <label>本次核验有效期（含 UTC offset）<input name="verification_expires_at" class="w-full rounded border p-2" required></label>
                <div data-repeat-container="availability-{{ $resource->id }}"><div data-repeat-row class="grid gap-3 md:grid-cols-2"><label>可服务班次开始（含 offset）<input name="availability[0][starts_at]" placeholder="2026-11-01T08:00:00+08:00" class="w-full rounded border p-2" required></label><label>可服务班次结束（含 offset）<input name="availability[0][ends_at]" class="w-full rounded border p-2" required></label></div></div>
                <button type="button" class="secondary-button self-start" data-add-row="availability-{{ $resource->id }}">增加明确班次</button>
                <label>出发/准备/休息最少缓冲（分钟）<input name="buffer_before_minutes" type="number" min="0" max="10080" class="w-full rounded border p-2" required></label>
                <label>返程/维护/休息最少缓冲（分钟）<input name="buffer_after_minutes" type="number" min="0" max="10080" class="w-full rounded border p-2" required></label>
                <button class="primary-button self-start">确认来源与班次核验，资源恢复可预留</button>
            </form>@endif
        </section>@empty<p>暂无资源，请先在工作台登记。</p>@endforelse
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">真实交易 SKU 映射</h2><p>每个价目版本与渠道明确绑定 SKU。机器人租用、人工、运输、押金映射非技能 virtual 服务 SKU，实物占用由资源台账负责。技能映射原商品并拆为独立技能子单，完整收款复核后才进入既有技能授权流程。</p>
            @if (bouncer()->hasPermission('catalog.scene-workflow.supply'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.sku-register') }}" class="mt-3 grid gap-3 md:grid-cols-2">@csrf
                <label>价目版本<select name="price_item_id" class="w-full rounded border p-2" required>@foreach ($prices as $price)<option value="{{ $price->id }}">#{{ $price->id }} {{ $price->code }} v{{ $price->version }} · {{ $price->name }}</option>@endforeach</select></label>
                <label>渠道<select name="channel_id" class="w-full rounded border p-2" required>@foreach ($channels as $channel)<option value="{{ $channel->id }}">{{ $channel->code }}</option>@endforeach</select></label>
                <label>真实 SKU 商品 ID<input name="product_id" type="number" min="1" class="w-full rounded border p-2" required></label>
                <label>映射方式<select name="mode" class="w-full rounded border p-2"><option value="service">履约服务费用</option><option value="skill">独立技能购买</option></select></label>
                <label>映射依据<input name="reason" minlength="3" maxlength="2000" class="w-full rounded border p-2" required></label><button class="primary-button self-start">登记映射，等待独立批准</button>
            </form>@endif
            @foreach ($mappings as $mapping)<div class="mt-3 border-t pt-3"><p>#{{ $mapping->id }} · 价目 #{{ $mapping->price_item_id }} / 渠道 #{{ $mapping->channel_id }} → 商品 #{{ $mapping->product_id }} · {{ $mapping->mode }} · {{ $mapping->state }}</p>
                @if (bouncer()->hasPermission('catalog.scene-workflow.supply_review') && in_array($mapping->state,['draft','approved']))<form method="POST" action="{{ route('admin.catalog.scene-workflow.sku-decision',['id'=>$mapping->id,'action'=>$mapping->state==='draft'?'approve':'revoke']) }}" class="mt-2">@csrf<input type="hidden" name="row_version" value="{{ $mapping->row_version }}"><button class="secondary-button">{{ $mapping->state==='draft'?'独立批准映射':'撤销映射并阻止新转换' }}</button></form>@endif
            </div>@endforeach
        </section>
    </div>
    @include('admin::catalog.scene-workflow.repeater')
</x-admin::layouts>
