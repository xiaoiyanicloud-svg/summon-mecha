<x-admin::layouts>
    <x-slot:title>生态发布</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态发布工作台</p>
            <p class="text-xs text-gray-500">
                活动 / 资讯的 revision、审核、排期、发布与处置。所有写动作都带行版本校验；
                页面过期时会提示“已被他人修改，请刷新”。
            </p>
        </div>

        <div class="flex items-center gap-x-2.5">
            @if (bouncer()->hasPermission('ecosystem.events.create'))
                <a href="{{ route('admin.catalog.ecosystem.events.create') }}" class="primary-button">新建活动</a>
            @endif

            @if (bouncer()->hasPermission('ecosystem.media.view'))
                <a href="{{ route('admin.catalog.ecosystem.media.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">媒体</a>
            @endif

            @if (bouncer()->hasPermission('ecosystem.content.view'))
                <a href="{{ route('admin.catalog.ecosystem.content.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">文章列表</a>
            @endif

            @if (bouncer()->hasPermission('ecosystem.organizers.view'))
                <a href="{{ route('admin.catalog.ecosystem.organizers.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">主办方</a>
            @endif

            <a href="{{ route('admin.catalog.events.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">旧赛事管理</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">活动（最近 50 条）</p>
            <span class="text-xs text-gray-500">publish_status / disposition / row_version / 公开版本 / 候选版本</span>
        </div>

        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">发布状态</th>
                    <th class="px-4 py-3">处置</th>
                    <th class="px-4 py-3">行版本</th>
                    <th class="px-4 py-3">公开 revision</th>
                    <th class="px-4 py-3">候选 revision</th>
                    <th class="px-4 py-3">排期（UTC）</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $event->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">{{ $event->name }}</td>
                        <td class="px-4 py-3 text-xs">{{ $event->kind ?: '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $event->publish_status }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs {{ $event->disposition === 'retracted' ? 'bg-red-100 text-red-800' : ($event->disposition === 'withheld' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') }}">
                                {{ $event->disposition }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $event->row_version }}</td>
                        <td class="px-4 py-3 text-xs">{{ $event->published_revision_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $event->approved_revision_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $event->scheduled_publish_at ? $event->scheduled_publish_at->utc()->format('Y-m-d H:i') : '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if (bouncer()->hasPermission('ecosystem.events.view'))
                                <a href="{{ route('admin.catalog.ecosystem.events.show', $event->id) }}" class="text-blue-600 hover:underline">工作页</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-gray-500">还没有活动记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">资讯（最近 50 条）</p>
            <span class="text-xs text-gray-500">slug 全局唯一；撤稿后不复用</span>
        </div>

        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">slug</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">发布状态</th>
                    <th class="px-4 py-3">处置</th>
                    <th class="px-4 py-3">行版本</th>
                    <th class="px-4 py-3">公开 revision</th>
                    <th class="px-4 py-3">候选 revision</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($posts as $post)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $post->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">{{ $post->slug }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->type }}</td>
                        <td class="px-4 py-3"><span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $post->publish_status }}</span></td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs {{ $post->disposition === 'retracted' ? 'bg-red-100 text-red-800' : ($post->disposition === 'withheld' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') }}">
                                {{ $post->disposition }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $post->row_version }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->published_revision_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->approved_revision_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            @if (bouncer()->hasPermission('ecosystem.content.view'))
                                <a href="{{ route('admin.catalog.ecosystem.content.show', $post->id) }}" class="text-blue-600 hover:underline">工作页</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-500">还没有资讯记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">主办方（最近 50 条）</p>
            @if (bouncer()->hasPermission('ecosystem.organizers.manage'))
                <a href="{{ route('admin.catalog.ecosystem.organizers.index') }}" class="text-blue-600 hover:underline text-sm">管理 / 新建</a>
            @endif
        </div>

        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">平台角色</th>
                    <th class="px-4 py-3">核验状态</th>
                    <th class="px-4 py-3">核验时间（UTC）</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organizers as $organizer)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $organizer->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">{{ $organizer->name }}</td>
                        <td class="px-4 py-3 text-xs">{{ $organizer->platform_role }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs {{ $organizer->status === 'verified' ? 'bg-green-100 text-green-800' : ($organizer->status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                                {{ $organizer->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $organizer->verified_at ? $organizer->verified_at->utc()->format('Y-m-d H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">还没有主办方记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
