<x-admin::layouts>
    <x-slot:title>场景服务工作台</x-slot>
    <div class="flex flex-col gap-6">
        <h1 class="text-xl font-bold">场景服务工作台</h1>
        <p>需求 {{ $metrics['requests'] }} · 已接受报价 {{ $metrics['accepted_quotes'] }} · 项目 {{ $metrics['confirmed_projects'] }} · 待办 {{ $metrics['open_todos'] }}。接受报价不表示已确认交付或收款。</p>
        <p>订单转换待事务验收。{{ $metrics['sla_status']==='calendar_approved'?'当前已批准工作日历，新需求将冻结受理时限。':'工作日历尚未批准，当前不提供响应时限承诺。' }}</p>
        <nav class="flex gap-4">@if (bouncer()->hasPermission('catalog.scene-workflow.policy_view'))<a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.configuration') }}">服务条款、资料保留与工作日历</a>@endif @if (bouncer()->hasPermission('catalog.scene-workflow.evidence_view'))<a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.evidence') }}">内容与具体设备任务证据审核</a>@endif</nav>
        @if ($errors->any())<div role="alert" class="rounded border border-red-400 p-4">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <section class="rounded border p-4">
            <h2 class="mb-3 text-lg font-semibold">需求受理</h2>
            <table class="w-full text-left"><thead><tr><th>需求号</th><th>状态</th><th>负责人</th><th>提交时间</th></tr></thead><tbody>
                @forelse ($requests as $r)<tr class="border-t"><td class="py-3"><a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.show', $r->id) }}">{{ $r->request_no }}</a></td><td>{{ $r->state }}</td><td>{{ $r->assignee_id ?: '待分派' }}</td><td>{{ $r->created_at }}</td></tr>
                @empty<tr><td colspan="4" class="py-4">暂无需求</td></tr>@endforelse
            </tbody></table>{{ $requests->links() }}
        </section>
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">可处理待办</h2>
            @forelse ($todos as $todo)<p class="py-2">{{ $todo->kind }} · @if ($todo->request_id)<a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.show', $todo->request_id) }}">处理需求 #{{ $todo->request_id }}</a>@endif · {{ $todo->assignee_id ?: '待分派' }}</p>@if (bouncer()->hasPermission('catalog.scene-workflow.operate'))<form method="POST" action="{{ route('admin.catalog.scene-workflow.todo-assign',$todo->id) }}" class="mb-3 flex flex-wrap gap-2">@csrf<input type="hidden" name="row_version" value="{{ $todo->row_version }}"><label>转派给<select name="assignee_id" required>@foreach ($admins as $admin)<option value="{{ $admin->id }}">{{ $admin->name }}</option>@endforeach</select></label><input name="reason" minlength="3" maxlength="2000" placeholder="转派依据，不重置截止时间" required><button class="secondary-button">转派待办</button></form>@endif @empty<p>暂无待办</p>@endforelse
        </section>
        @if (bouncer()->hasPermission('catalog.scene-workflow.publish') || bouncer()->hasPermission('catalog.scene-workflow.revision_write') || bouncer()->hasPermission('catalog.scene-workflow.revision_review'))
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">发布版本</h2>
            <p class="mb-3">修订通过新建版本进行。发布须由另一位审核人批准；公开内容读取当前发布版本。</p>
            @if (bouncer()->hasPermission('catalog.scene-workflow.revision_write'))<form method="POST" id="scene-draft-form" class="flex flex-col gap-3">@csrf
                <label>场景<select name="scene_id" id="scene-draft-id" class="w-full rounded border p-2" required>@foreach ($scenes as $s)<option value="{{ $s->id }}">{{ $s->name }}（#{{ $s->id }}）</option>@endforeach</select></label>
                <label>名称<input name="name" maxlength="255" class="w-full rounded border p-2" required></label>
                <label>类别<select name="category" class="w-full rounded border p-2">@foreach (\Webkul\Shop\Models\Scene::CATEGORIES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label>服务说明<textarea name="description" maxlength="20000" class="w-full rounded border p-2" required></textarea></label>
                <label>服务标签<input name="tags[0]" maxlength="80" class="w-full rounded border p-2" required></label>
                <label>人工履约模板<select name="workflow_template" class="w-full rounded border p-2"><option value="manual_site_v1">现场服务 v1：出库 → 运输 → 布场 → 彩排 → 服务</option></select></label>
                <label>交付内容<input name="deliverables[0]" maxlength="1000" class="w-full rounded border p-2" required></label>
                <label>验收项编号<input name="acceptance[0][key]" pattern="[a-z][a-z0-9_]{0,79}" class="w-full rounded border p-2" required></label>
                <label>验收标准<input name="acceptance[0][label]" maxlength="1000" class="w-full rounded border p-2" required></label>
                <input name="acceptance[0][required]" type="hidden" value="1"><input name="robots" type="hidden" value="">
                <p>首版可不配置展示机型；需求评估时必须关联真实设备与能力证据。</p>
                <button class="primary-button self-start">建立新草稿</button>
            </form>@endif
            @foreach ($revisions as $revision)<div class="mt-4 flex flex-wrap items-center gap-3 border-t pt-3"><span>场景 #{{ $revision->scene_id }} / 第 {{ $revision->revision_no }} 版 · {{ $revision->state }}</span>@if (bouncer()->hasPermission('catalog.scene-workflow.revision_write'))<a class="text-blue-600" href="{{ route('admin.catalog.scene-workflow.revision-editor',$revision->id) }}">查看、编辑或比较版本</a>@endif
                @foreach (['draft' => 'review', 'in_review' => 'approve', 'approved' => 'publish', 'published' => 'withdraw'] as $state => $action)
                    @if ($revision->state === $state && bouncer()->hasPermission('catalog.scene-workflow.'.($action === 'review' ? 'revision_write' : ($action === 'approve' ? 'revision_review' : 'publish'))))<form method="POST" action="{{ route('admin.catalog.scene-workflow.publication', ['id' => $revision->scene_id, 'action' => $action]) }}">@csrf<input type="hidden" name="revision_id" value="{{ $revision->id }}"><input type="hidden" name="row_version" value="{{ $revision->row_version }}"><button class="secondary-button">{{ ['review'=>'送审','approve'=>'批准','publish'=>'发布','withdraw'=>'撤下'][$action] }}</button></form>@endif
                @endforeach
            </div>@endforeach
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.supply'))
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">新增服务价目版本</h2>
            <p>金额填写最小货币单位（人民币分），显式填写税率；旧版本不会被覆盖。</p>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.supply', 'prices') }}" class="grid gap-3 md:grid-cols-2">@csrf
                @foreach (['code'=>'价目编码','name'=>'名称','product_id'=>'商品 ID（服务可留空）','unit_minor'=>'单价（分）','tax_bps'=>'税率（万分比）','valid_from'=>'有效期开始（含时区）','valid_until'=>'有效期结束（含时区）'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" class="w-full rounded border p-2" @if ($key !== 'product_id') required @endif></label>@endforeach
                <label>类型<select name="kind" class="w-full rounded border p-2">@foreach (['robot'=>'机器人','service'=>'现场服务','transport'=>'运输','operator'=>'人员','accessory'=>'配件','skill'=>'已核验技能'] as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
                <label>单位<select name="unit" class="w-full rounded border p-2">@foreach (['day','shift','trip','item','project','hour'] as $unit)<option>{{ $unit }}</option>@endforeach</select></label>
                <label>费用性质<select name="charge_type" class="w-full rounded border p-2"><option value="fee">服务费用</option><option value="deposit">押金</option></select></label><input type="hidden" name="currency" value="CNY"><button class="primary-button self-start">保存价目版本</button>
            </form>
            @foreach ($prices as $price)<p class="mt-2">#{{ $price->id }} {{ $price->code }} v{{ $price->version }} · {{ $price->name }} · {{ $price->unit_minor }} 分 / {{ $price->unit }}</p>@endforeach
        </section>
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">记录具体设备任务证据</h2>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.supply', 'evidence') }}" class="grid gap-3 md:grid-cols-2">@csrf
                @foreach (['product_id'=>'商品 ID','binding_id'=>'设备 binding ID','capability'=>'任务能力编码','scope'=>'核验环境与适用边界','source_reference'=>'核验资料索引','artifact_hash'=>'资料 SHA-256','verified_at'=>'实际核验时间（含时区）','expires_at'=>'核验有效期（含时区）'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" class="w-full rounded border p-2" required></label>@endforeach
                <label>交付级别<select name="level" class="w-full rounded border p-2"><option>L1</option><option>L2</option><option>L3</option></select></label><button class="primary-button self-start">记录人工核验</button>
                <fieldset class="md:col-span-2"><legend>实际核验的适用场景类别</legend>@foreach (\Webkul\Shop\Models\Scene::CATEGORIES as $key=>$label)<label class="mr-3"><input type="checkbox" name="constraints[categories][]" value="{{ $key }}">{{ $label }}</label>@endforeach</fieldset>
                @foreach (['max_payload_kg'=>'最大载荷 kg','min_clearance_mm'=>'最小净宽 mm','max_slope_deg'=>'最大坡度 度','max_throughput_units_hour'=>'最大节拍 件/小时','max_route_length_m'=>'最大路线 m','max_sessions'=>'最大场次','max_participants'=>'最多人数','max_floors'=>'最多楼层'] as $key=>$label)<label>{{ $label }}<input name="constraints[{{ $key }}]" type="number" min="0" step="{{ in_array($key,['max_payload_kg','max_slope_deg','max_route_length_m'])?'0.001':'1' }}" class="w-full rounded border p-2" placeholder="未核验留空"></label>@endforeach
                @foreach (['outdoor_verified'=>'室外工况','hazardous_environment_verified'=>'高危工况','production_verified'=>'生产工况'] as $key=>$label)<label>{{ $label }}<select name="constraints[{{ $key }}]"><option value="">未知</option><option value="1">已专项核验</option><option value="0">不支持</option></select></label>@endforeach
                @foreach (['version_id'=>'技能版本 ID','asset_id'=>'技能产物 ID','device_id'=>'设备登记 ID'] as $key=>$label)<label>{{ $label }}（非技能留空）<input name="skill[{{ $key }}]" type="number" min="1" class="w-full rounded border p-2"></label>@endforeach
            </form>
        </section>
        <section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">登记逐台资源</h2>
            <form method="POST" action="{{ route('admin.catalog.scene-workflow.supply', 'resources') }}" class="grid gap-3 md:grid-cols-2">@csrf
                @foreach (['resource_no'=>'资源唯一编号','product_id'=>'商品 ID','binding_id'=>'设备 binding ID','external_reference'=>'供给来源与核验索引','city'=>'服务城市','timezone'=>'服务地 IANA 时区','verification_expires_at'=>'核验有效期（含时区）'] as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" class="w-full rounded border p-2" @if (!in_array($key,['product_id','binding_id'])) required @endif></label>@endforeach
                <label>类型<select name="kind" class="w-full rounded border p-2"><option value="robot">机器人</option><option value="operator">人员</option><option value="accessory">配件</option></select></label><button class="primary-button self-start">记录核验资源</button>
            </form>
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.reserve'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">资源异常</h2>
            @forelse ($resources as $resource)<form method="POST" action="{{ route('admin.catalog.scene-workflow.pause', $resource->id) }}" class="mt-2 flex flex-wrap gap-2">@csrf<span>#{{ $resource->id }} {{ $resource->resource_no }} · {{ $resource->state }}</span><input type="hidden" name="row_version" value="{{ $resource->row_version }}"><input name="reason" placeholder="故障或不可用原因" class="rounded border p-2" required><button class="secondary-button">暂停并生成影响待办</button></form>@empty<p>暂无已登记资源</p>@endforelse
        </section>@endif
        @if (bouncer()->hasPermission('catalog.scene-workflow.operate'))<section class="rounded border p-4"><h2 class="mb-3 text-lg font-semibold">事件恢复</h2>
            @forelse ($failures as $failure)<form method="POST" action="{{ route('admin.catalog.scene-workflow.replay', $failure->id) }}" class="mt-2 flex gap-3">@csrf<span>#{{ $failure->id }} {{ $failure->event_type }} / {{ $failure->last_error_code }} / 尝试 {{ $failure->attempts }} 次</span><button class="secondary-button">重新投递</button></form>@empty<p>暂无失败事件</p>@endforelse
        </section>@endif
    </div>
    @push('scripts')<script>
        document.getElementById('scene-draft-form')?.addEventListener('submit', function () {
            this.action = @json(route('admin.catalog.scene-workflow.publication', ['id'=>'__ID__','action'=>'draft'])).replace('__ID__', document.getElementById('scene-draft-id').value);
        });
    </script>@endpush
</x-admin::layouts>
