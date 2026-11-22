<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.dashboard.index.title')
    </x-slot>

    <!-- User Details Section -->
    <div class="mb-5 flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="grid gap-1.5">
            <p class="text-xl font-bold !leading-normal text-gray-800 dark:text-white">
                @lang('admin::app.dashboard.index.user-name', ['user_name' => auth()->guard('admin')->user()->name])
            </p>

            <p class="!leading-normal text-gray-600 dark:text-gray-300">
                @lang('admin::app.dashboard.index.user-info')
            </p>
        </div>

        <!-- Actions -->
        <v-dashboard-filters>
            <!-- Shimmer -->
            <div class="flex gap-1.5">
                <div class="shimmer h-[39px] w-[132px] rounded-md"></div>
                <div class="shimmer h-[39px] w-[140px] rounded-md"></div>
                <div class="shimmer h-[39px] w-[140px] rounded-md"></div>
            </div>
        </v-dashboard-filters>
    </div>

    {{-- 召唤机甲运营聚合工作台（仅展示当前管理员有权处理的待办与真实开关状态） --}}
    @if (! empty($workbench))
        <div class="sm-wb-wrap mt-4" data-testid="summon-mecha-workbench">
            <div class="sm-wb-hero">
                <div>
                    <div class="sm-wb-hero-badge">
                        SUMMON MECHA · 具身智能机器人技能运营中心
                    </div>
                    <h2 class="sm-wb-hero-title">
                        今日运营工作台 · {{ ($workbench['total_pending'] ?? 0) > 0 ? '当前共 '.$workbench['total_pending'].' 项待处理事项' : '全业务待办队列已清零' }}
                    </h2>
                    <p class="sm-wb-hero-sub">
                        汇总技能审核、合作入驻、平台巡检告警、售后退款、客服工单与故障追溯实时待办（严格遵循角色权限隔离） · 更新于 {{ $workbench['generated_at'] ?? '' }}
                    </p>
                </div>

                <div class="sm-wb-hero-actions">
                    @foreach (($workbench['shortcuts'] ?? []) as $index => $shortcut)
                        <a
                            href="{{ $shortcut['route'] }}"
                            class="{{ $index === 0 ? 'sm-wb-btn-primary' : 'sm-wb-btn-ghost' }}"
                        >
                            {{ $shortcut['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if (! empty($workbench['queues']))
                <div class="sm-wb-grid">
                    @foreach ($workbench['queues'] as $item)
                        @php
                            $toneClass = match ($item['tone'] ?? 'neutral') {
                                'danger'  => 'is-urgent',
                                'warning' => 'is-warn',
                                'primary' => 'is-info',
                                default   => 'is-ok',
                            };
                            $toneLabel = match ($item['tone'] ?? 'neutral') {
                                'danger'  => '高优处理',
                                'warning' => '待跟进',
                                'primary' => '待审核',
                                default   => ($item['count'] > 0 ? '进行中' : '正常'),
                            };
                        @endphp
                        <a href="{{ $item['route'] }}" class="sm-wb-card">
                            <div class="sm-wb-card-top">
                                <span class="sm-wb-card-label">{{ $item['title'] }}</span>
                                <span class="sm-wb-pill {{ $toneClass }}">{{ $toneLabel }}</span>
                            </div>
                            <div class="sm-wb-card-val">{{ $item['count'] }}</div>
                            <div class="sm-wb-card-meta flex items-center justify-between gap-2">
                                <span>{{ $item['meta'] }}</span>
                                <span class="font-semibold text-blue-600 dark:text-blue-400">{{ $item['cta'] }} →</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            @if (! empty($workbench['readiness']))
                <div class="sm-wb-status-bar">
                    <span class="font-semibold text-gray-700 dark:text-gray-200">平台运行闸门与通道状态：</span>
                    @foreach ($workbench['readiness'] as $flag)
                        <span class="inline-flex items-center">
                            <span class="sm-wb-dot {{ $flag['enabled'] ? 'on' : 'off' }}"></span>
                            <span class="font-medium">{{ $flag['label'] }}：</span>
                            <span>{{ $flag['text'] }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="sm-wb-section-divider">
                <span>以下为 Bagisto 电商底层交易概览（已开票财务口径，与上方技能平台经营口径区分展示）</span>
                @if (bouncer()->hasPermission('dashboard.overview'))
                    <a href="{{ route('admin.dashboard.ops') }}" class="text-blue-600 hover:underline dark:text-blue-400">
                        切换至技能平台真实经营总览 →
                    </a>
                @endif
            </div>
        </div>
    @endif

    {{--
        口径说明（本项目新增，非 Bagisto 原生）

        ⚠ 必须写在页面上，不能只写在这段注释里：本页每个金额都取自 Bagisto 原生报表，
        而原生报表算的是「已开票金额」，不是「收到的钱」。这不是本平台的业务口径 ——
        两套口径并排摆着却不说清，读的人一定会把"销售额 ¥0.00"理解成"没有生意"，
        而事实是"从未开过发票"。这类"数字对、含义错"的误读比没有数字更危险。
    --}}
    <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
        <p class="font-semibold">
            本页数字按 Bagisto 原生报表口径计算，与平台业务口径不同
        </p>

        <ul class="mt-1 list-disc pl-5">
            <li>
                「总销售额 / 平均销售额」= 所选期间内订单的
                <span class="font-semibold">已开票金额 − 已退款</span>。
                <span class="font-semibold">未付款、未开票的订单不计入</span>
                —— 因此显示 ¥0 的含义是「没有开过发票」，不等于「没有订单」。
            </li>

            <li>
                「未付发票」= 状态为待处理的发票金额合计。平台尚未启用发票流程，故恒为 0。
            </li>

            <li>
                平台真实经营口径（含明确指出「未接入」的维度）见
                <a
                    href="{{ route('admin.dashboard.ops') }}"
                    class="font-semibold underline"
                >运营总览</a>。
            </li>
        </ul>
    </div>

    <!-- Body Component -->
    <div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">
        <!-- Left Section -->
        <div class="flex flex-1 flex-col gap-8 max-xl:flex-auto">
            {!! view_render_event('bagisto.admin.dashboard.overall_details.before') !!}

            <!-- Overall Details -->
            <div class="flex flex-col gap-2">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                    @lang('admin::app.dashboard.index.overall-details')
                </p>

                <!-- Over All Details Section -->
                @include('admin::dashboard.over-all-details')
            </div>

            {!! view_render_event('bagisto.admin.dashboard.overall_details.after') !!}

            {!! view_render_event('bagisto.admin.dashboard.todays_details.before') !!}

            <!-- Todays Details -->
            <div class="flex flex-col gap-2">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                    @lang('admin::app.dashboard.index.today-details')
                </p>

                <!-- Todays Details Section -->
                @include('admin::dashboard.todays-details')
            </div>

            {!! view_render_event('bagisto.admin.dashboard.todays_details.after') !!}

            {!! view_render_event('bagisto.admin.dashboard.stock_threshold.before') !!}

            <!-- Stock Threshold -->
            <div class="flex flex-col gap-2">
                <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                    @lang('admin::app.dashboard.index.stock-threshold')
                </p>

                <!-- Products List -->  
                @include('admin::dashboard.stock-threshold-products')
            </div>
            
            {!! view_render_event('bagisto.admin.dashboard.stock_threshold.after') !!}
        </div>

        <!-- Right Section -->
        <div class="flex w-[360px] max-w-full flex-col gap-2 max-sm:w-full">
            <!-- First Component -->
            <p class="text-base font-semibold text-gray-600 dark:text-gray-300">
                @lang('admin::app.dashboard.index.store-stats')
            </p>

            {!! view_render_event('bagisto.admin.dashboard.store_stats.before') !!}

            <!-- Store Stats -->
            <div class="box-shadow rounded bg-white dark:bg-gray-900">
                <!-- Total Sales Details -->
                @include('admin::dashboard.total-sales')

                <!-- Total Visitors Details -->
                @include('admin::dashboard.total-visitors')

                <!-- Top Selling Products -->
                @include('admin::dashboard.top-selling-products')

                <!-- Top Customers -->
                @include('admin::dashboard.top-customers')
            </div>

            {!! view_render_event('bagisto.admin.dashboard.store_stats.after') !!}
        </div>
    </div>
    
    @pushOnce('scripts')
        <script
            type="module"
            src="{{ bagisto_asset('js/chart.js') }}"
        >
        </script>

        <script
            type="text/x-template"
            id="v-dashboard-filters-template"
        >
            <div class="flex gap-1.5">
                <template v-if="channels.length > 2">
                    <x-admin::dropdown position="bottom-right">
                        <x-slot:toggle>
                            <button
                                type="button"
                                class="inline-flex w-full cursor-pointer appearance-none items-center justify-between gap-x-2 rounded-md border bg-white px-2.5 py-1.5 text-center text-sm leading-6 text-gray-600 transition-all marker:shadow hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400"
                            >
                                @{{ channels.find(channel => channel.code == filters.channel).name }}
                                
                                <span class="icon-sort-down text-2xl"></span>
                            </button>
                        </x-slot>

                        <x-slot:menu class="!p-0 shadow-[0_5px_20px_rgba(0,0,0,0.15)] dark:border-gray-800">
                            <x-admin::dropdown.menu.item
                                v-for="channel in channels"
                                ::class="{'bg-gray-100 dark:bg-gray-950': channel.code == filters.channel}"
                                @click="filters.channel = channel.code"
                            >
                                @{{ channel.name }}
                            </x-admin::dropdown.menu.item>
                        </x-slot>
                    </x-admin::dropdown>
                </template>

                <x-admin::flat-picker.date class="!w-[140px]" ::allow-input="false">
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.start"
                        placeholder="@lang('admin::app.dashboard.index.start-date')"
                    />
                </x-admin::flat-picker.date>

                <x-admin::flat-picker.date class="!w-[140px]" ::allow-input="false">
                    <input
                        class="flex min-h-[39px] w-full rounded-md border px-3 py-2 text-sm text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400"
                        v-model="filters.end"
                        placeholder="@lang('admin::app.dashboard.index.end-date')"
                    />
                </x-admin::flat-picker.date>
            </div>
        </script>

        <script type="module">
            app.component('v-dashboard-filters', {
                template: '#v-dashboard-filters-template',

                data() {
                    return {
                        channels: [
                            {
                                name: "@lang('admin::app.dashboard.index.all-channels')",
                                code: ''
                            },
                            ...@json(core()->getAllChannels()),
                        ],
                        
                        filters: {
                            channel: '',

                            start: "{{ $startDate->format('Y-m-d') }}",
                            
                            end: "{{ $endDate->format('Y-m-d') }}",
                        }
                    }
                },

                watch: {
                    filters: {
                        handler() {
                            this.$emitter.emit('reporting-filter-updated', this.filters);
                        },

                        deep: true
                    }
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
