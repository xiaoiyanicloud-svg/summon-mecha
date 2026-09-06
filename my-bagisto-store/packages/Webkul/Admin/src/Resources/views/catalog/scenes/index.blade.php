<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.catalog.scenes.index.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            @lang('admin::app.catalog.scenes.index.title')
        </p>

        <div class="flex items-center gap-x-2.5">
            @if (bouncer()->hasPermission('catalog.scenes.create'))
                <a href="{{ route('admin.catalog.scenes.create') }}">
                    <div class="primary-button">
                        @lang('admin::app.catalog.scenes.index.add-btn')
                    </div>
                </a>
            @endif
        </div>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.scenes.index')" />

    {{-- 状态列开关 AJAX 切换：点一下状态徽标即切换，不整页刷新 --}}
    @push('scripts')
        <script>
            window.__sceneCsrf = '{{ csrf_token() }}';

            window.__sceneToggle = function (btn) {
                var url = btn.dataset.route;
                var current = btn.dataset.current;

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.__sceneCsrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ status: current === '1' ? 0 : 1 })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        var on = !!d.status;
                        btn.dataset.current = on ? '1' : '0';
                        btn.className = on
                            ? 'badge badge-md badge-success cursor-pointer transition hover:opacity-80'
                            : 'badge badge-md badge-danger cursor-pointer transition hover:opacity-80';
                        btn.textContent = on ? btn.dataset.onText : btn.dataset.offText;
                    } else {
                        location.reload();
                    }
                })
                .catch(function () { location.reload(); });
            };
        </script>

        <script>
            window.__sceneHotToggle = function (btn) {
                var url = btn.dataset.route;

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.__sceneCsrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({})
                })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.success) {
                        var on = !!d.is_hot;
                        btn.dataset.current = on ? '1' : '0';
                        btn.className = on
                            ? 'badge badge-md badge-success cursor-pointer transition hover:opacity-80'
                            : 'badge badge-md badge-danger cursor-pointer transition hover:opacity-80';
                        btn.textContent = on ? btn.dataset.onText : btn.dataset.offText;
                    } else {
                        location.reload();
                    }
                })
                .catch(function () { location.reload(); });
            };
        </script>

        <script>
            window.__sceneConsult = function (input) {
                var url = input.dataset.route;
                var val = parseInt(input.value, 10);
                if (isNaN(val) || val < 0) { val = 0; input.value = 0; }

                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.__sceneCsrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ consult_count: val })
                })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (!d.success) { location.reload(); } })
                .catch(function () { location.reload(); });
            };
        </script>
    @endpush
</x-admin::layouts>
