<x-admin::layouts>
    <x-slot:title>技能管理</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">技能管理</p>
    </div>

    {{-- 提示:后台没有统一的 flash 组件,这里自己渲染 --}}
    @if (session('success'))
        <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">技能包怎么交付到机器人上</p>
        <p class="mt-1 leading-relaxed">
            机器人是第三方成品、固件改不了,厂家也没有面向第三方平台的分发接口,所以平台
            <b>无法自动下发</b>。当前的做法是:用户下单后在 App 里下载技能包,按你填写的
            <b>安装指引</b>自行装到机器上,装完由用户点「我已安装完成」。
            安装指引在下面每个技能的版本里维护。
        </p>
    </div>

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">技能商品</th>
                    <th class="px-4 py-3">已发布版本</th>
                    <th class="px-4 py-3">全部版本</th>
                    <th class="px-4 py-3">交付物</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php
                        $versions = $versionsByProduct->get($product->id) ?? collect();
                        $published = $versions->firstWhere('status', 'published');
                        $assetCount = $versions->sum(fn ($v) => $v->assets->count());
                    @endphp
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800 dark:text-white">{{ $product->name }}</div>
                            <div class="text-xs text-gray-500">SKU: {{ $product->sku }}　ID: {{ $product->id }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($published)
                                <span class="rounded bg-green-100 px-2 py-1 text-xs text-green-800">
                                    v{{ $published->version }}
                                </span>
                            @else
                                <span class="text-xs text-gray-400">未发布</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $versions->count() }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $assetCount }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.catalog.skills.edit', $product->id) }}">
                                <span class="cursor-pointer text-blue-600 hover:underline">管理版本</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                            技能分类下还没有商品。<br>
                            <span class="text-xs">
                                技能商品仍然是普通商品,请先到「目录 → 产品」新建商品并挂到「技能」分类下,再回来管理它的版本。
                            </span>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
