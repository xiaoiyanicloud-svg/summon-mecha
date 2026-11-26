<x-admin::layouts>
    <x-slot:title>品牌管理</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">品牌管理（RaaS 生态品牌与属性同步）</p>

        <div class="flex items-center gap-x-2.5">
            @if (bouncer()->hasPermission('catalog.brands.edit'))
                <form method="POST" action="{{ route('admin.catalog.brands.sync_taxonomy') }}">
                    @csrf
                    <button type="submit" class="secondary-button">同步 RaaS 分类与初始品牌</button>
                </form>

                <form method="POST" action="{{ route('admin.catalog.brands.backfill_products') }}">
                    @csrf
                    <button type="submit" class="transparent-button border border-gray-300">回扫存量商品品牌/形态</button>
                </form>
            @endif

            @if (bouncer()->hasPermission('catalog.brands.create'))
                <a href="{{ route('admin.catalog.brands.create') }}">
                    <div class="primary-button">新建品牌</div>
                </a>
            @endif
        </div>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">分类页左侧品牌栏 + 商品品牌属性 + 机型库厂商映射统一在此维护</p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            在此新建或修改品牌会自动同步至商品编辑页的「品牌」下拉选项，并立即生效于前台「租售」左侧品牌栏；
            关联 <code>device_model</code> 厂商编码后，前台可直接聚合展示对应机型的交付能力与兼容数。已有商品或机型引用的品牌仅允许停用，禁止硬删除。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.brands.index')" />

    @push('scripts')
        <script>
            window.__brandCsrf = '{{ csrf_token() }}';

            window.__brandToggleStatus = function (btn) {
                var url = btn.dataset.route;
                var next = btn.dataset.current === '1' ? 0 : 1;

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.__brandCsrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ status: next })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        var on = !!d.status;
                        btn.dataset.current = on ? '1' : '0';
                        btn.className = on
                            ? 'badge badge-md badge-success cursor-pointer transition hover:opacity-80'
                            : 'badge badge-md badge-danger cursor-pointer transition hover:opacity-80';
                        btn.textContent = on ? '启用' : '停用';
                    } else {
                        location.reload();
                    }
                })
                .catch(function () { location.reload(); });
            };

            window.__brandToggleHot = function (btn) {
                fetch(btn.dataset.route, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.__brandCsrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({})
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        var on = !!d.is_hot;
                        btn.className = on
                            ? 'badge badge-md badge-warning cursor-pointer transition hover:opacity-80'
                            : 'badge badge-md badge-secondary cursor-pointer transition hover:opacity-80';
                        btn.textContent = on ? '热门 TOP' : '常规';
                    } else {
                        location.reload();
                    }
                })
                .catch(function () { location.reload(); });
            };
        </script>
    @endpush
</x-admin::layouts>
