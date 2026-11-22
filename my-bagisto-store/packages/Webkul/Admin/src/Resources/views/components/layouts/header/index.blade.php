@php
    $admin = auth()->guard('admin')->user();
@endphp

<header class="sticky top-0 z-[10001] flex items-center justify-between border-b bg-white px-2 py-2 dark:border-gray-800 dark:bg-gray-900 sm:px-4 sm:py-2.5">
    <div class="flex items-center gap-1 sm:gap-1.5">
        <!-- Hamburger Menu -->
        <i
            class="icon-menu cursor-pointer rounded-md p-1.5 text-xl hover:bg-gray-100 dark:hover:bg-gray-950 lg:hidden sm:text-2xl"
            @click="$refs.sidebarMenuDrawer.open()"
        >
        </i>

        <!-- Logo -->
        <a href="{{ route('admin.dashboard.index') }}" class="flex-shrink-0">
            @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
                <img
                    class="h-8 w-auto sm:h-10"
                    src="{{ Storage::url($logo) }}"
                    alt="{{ config('app.name') }}"
                />
            @else
                <img
                    src="{{ request()->cookie('dark_mode') ? bagisto_asset('images/dark-logo.svg') : bagisto_asset('images/logo.svg') }}"
                    class="h-8 w-auto sm:h-10"
                    id="logo-image"
                    alt="{{ config('app.name') }}"
                />
            @endif
        </a>

        <!-- Mega Search Bar Vue Component -->
        <v-mega-search class="hidden sm:block">
            <div class="relative flex w-[200px] items-center sm:w-[300px] md:w-[400px] lg:w-[525px] xl:max-w-[525px] ltr:ml-2 rtl:mr-2 sm:ltr:ml-2.5 sm:rtl:mr-2.5">
                <i class="icon-search absolute top-1.5 flex items-center text-xl ltr:left-2 rtl:right-2 sm:text-2xl sm:ltr:left-3 sm:rtl:right-3"></i>

                <input 
                    type="text" 
                    class="block w-full rounded-lg border bg-white px-8 py-1.5 text-sm leading-6 text-gray-600 transition-all hover:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 sm:px-10 sm:text-base"
                    placeholder="@lang('admin::app.components.layouts.header.mega-search.title')" 
                >
            </div>
        </v-mega-search>
    </div>

    <div class="flex items-center gap-1 sm:gap-2.5">
        <!-- Dark mode Switcher -->
        <v-dark>
            <div class="flex">
                <span
                    class="{{ request()->cookie('dark_mode') ? 'icon-light' : 'icon-dark' }} cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950 sm:text-2xl"
                ></span>
            </div>
        </v-dark>

        <!-- Visit Shop Link -->
        <a 
            href="{{ route('shop.home.index') }}" 
            target="_blank"
            class="hidden sm:flex"
        >
            <span 
                class="icon-store cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950 sm:text-2xl"
                title="@lang('admin::app.components.layouts.header.visit-shop')"
            >
            </span>
        </a>

       <!-- Notification Component -->
        <v-notifications {{ $attributes }}>
            <span class="relative flex">
                <span 
                    class="icon-notification cursor-pointer rounded-md p-1.5 text-xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950 sm:text-2xl" 
                    title="@lang('admin::app.components.layouts.header.notifications')"
                >
                </span>
            </span>
        </v-notifications>

        <!-- Admin profile -->
        <x-admin::dropdown position="bottom-{{ core()->getCurrentLocale()->direction === 'ltr' ? 'right' : 'left' }}">
            <x-slot:toggle>
                @if ($admin->image)
                    <button class="flex h-8 w-8 cursor-pointer overflow-hidden rounded-full hover:opacity-80 focus:opacity-80 sm:h-9 sm:w-9">
                        <img
                            src="{{ $admin->image_url }}"
                            class="h-full w-full object-cover"
                        />
                    </button>
                @else
                    <button class="flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-blue-400 text-xs font-semibold leading-6 text-white transition-all hover:bg-blue-500 focus:bg-blue-500 sm:h-9 sm:w-9 sm:text-sm">
                        {{ substr($admin->name, 0, 1) }}
                    </button>
                @endif
            </x-slot>

            <!-- Admin Dropdown -->
            <x-slot:content class="!p-0">
                <div class="flex items-center gap-2 border border-b-gray-300 px-4 py-2 dark:border-gray-800 sm:px-5 sm:py-2.5">
                    <img
                        src="{{ asset('themes/admin/default/sm-favicon.svg') }}"
                        class="h-6 w-6 rounded"
                        width="24"
                        height="24"
                        alt="召唤机甲"
                    />

                    <!-- Version -->
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-300 sm:text-sm">
                        召唤机甲 · v{{ core()->version() }}
                    </p>
                </div>

                <div class="grid gap-1 pb-2.5">
                    <a
                        class="cursor-pointer px-4 py-2 text-sm text-gray-800 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-950 sm:px-5 sm:text-base"
                        href="{{ route('admin.account.edit') }}"
                    >
                        @lang('admin::app.components.layouts.header.my-account')
                    </a>

                    <!--Admin logout-->
                    <x-admin::form
                        method="DELETE"
                        action="{{ route('admin.session.destroy') }}"
                        id="adminLogout"
                    >
                    </x-admin::form>

                    <a
                        class="cursor-pointer px-4 py-2 text-sm text-gray-800 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-950 sm:px-5 sm:text-base"
                        href="{{ route('admin.session.destroy') }}"
                        onclick="event.preventDefault(); document.getElementById('adminLogout').submit();"
                    >
                        @lang('admin::app.components.layouts.header.logout')
                    </a>
                </div>
            </x-slot>
        </x-admin::dropdown>
    </div>
</header>

<!-- Menu Sidebar Drawer -->
<x-admin::drawer
    position="left"
    width="270px"
    ref="sidebarMenuDrawer"
>
    <!-- Drawer Header -->
    <x-slot:header>
        <div class="flex items-center justify-between">
            @if ($logo = core()->getConfigData('general.design.admin_logo.logo_image'))
                <img
                    src="{{ Storage::url($logo) }}"
                    class="h-8 w-auto sm:h-10"
                    alt="{{ config('app.name') }}"
                />
            @else
                <img
                    src="{{ request()->cookie('dark_mode') ? bagisto_asset('images/dark-logo.svg') : bagisto_asset('images/logo.svg') }}"
                    class="h-8 w-auto sm:h-10"
                    id="logo-image"
                    alt="{{ config('app.name') }}"
                />
            @endif
        </div>
    </x-slot>

    <!-- Drawer Content -->
    <x-slot:content class="p-3 sm:p-4">
        <div class="journal-scroll h-[calc(100vh-100px)] overflow-auto">
            <nav class="grid w-full gap-1.5 sm:gap-2">
                <!-- Navigation Menu -->
                @foreach (menu()->getItems('admin') as $menuItem)
                    <div class="group/item relative">
                        <a
                            href="{{ $menuItem->getUrl() }}"
                            class="flex items-center gap-2 p-1.5 cursor-pointer hover:rounded-lg {{ $menuItem->isActive() == 'active' ? 'bg-blue-600 rounded-lg' : ' hover:bg-gray-100 hover:dark:bg-gray-950' }} peer sm:gap-2.5"
                        >
                            <span class="{{ $menuItem->getIcon() }} text-xl {{ $menuItem->isActive() ? 'text-white' : ''}} sm:text-2xl"></span>
                            
                            <p class="font-semibold text-gray-600 dark:text-gray-300 whitespace-nowrap text-sm group-[.sidebar-collapsed]/container:hidden {{ $menuItem->isActive() ? 'text-white' : ''}} sm:text-base">
                                {{ $menuItem->getName() }}
                            </p>
                        </a>

                        @if ($menuItem->haveChildren())
                            <div class="{{ $menuItem->isActive() ? ' !grid bg-gray-100 dark:bg-gray-950' : '' }} hidden min-w-[180px] ltr:pl-8 rtl:pr-8 pb-2 rounded-b-lg z-[100] sm:ltr:pl-10 sm:rtl:pr-10">
                                @foreach ($menuItem->getChildren() as $subMenuItem)
                                    <a
                                        href="{{ $subMenuItem->getUrl() }}"
                                        class="text-xs text-{{ $subMenuItem->isActive() ? 'blue':'gray' }}-600 dark:text-{{ $subMenuItem->isActive() ? 'blue':'gray' }}-300 whitespace-nowrap py-1 group-[.sidebar-collapsed]/container:px-4 group-[.sidebar-collapsed]/container:py-2 group-[.inactive]/item:px-4 group-[.inactive]/item:py-2 hover:text-blue-600 dark:hover:bg-gray-950 sm:text-sm sm:group-[.sidebar-collapsed]/container:px-5 sm:group-[.sidebar-collapsed]/container:py-2.5 sm:group-[.inactive]/item:px-5 sm:group-[.inactive]/item:py-2.5"
                                    >
                                        {{ $subMenuItem->getName() }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </nav>
        </div>
    </x-slot>
</x-admin::drawer>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-mega-search-template"
    >
        <div class="relative flex w-[200px] items-center sm:w-[300px] md:w-[400px] lg:w-[525px] xl:max-w-[525px] ltr:ml-2 rtl:mr-2 sm:ltr:ml-2.5 sm:rtl:mr-2.5">
            <i class="icon-search absolute top-1.5 flex items-center text-xl ltr:left-2 rtl:right-2 sm:text-2xl sm:ltr:left-3 sm:rtl:right-3"></i>

            <input 
                type="text"
                class="peer block w-full rounded-lg border bg-white px-8 py-1.5 text-sm leading-6 text-gray-600 transition-all hover:border-gray-400 focus:border-gray-400 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-400 dark:focus:border-gray-400 sm:px-10 sm:text-base"
                :class="{'border-gray-400': isDropdownOpen}"
                placeholder="@lang('admin::app.components.layouts.header.mega-search.title')"
                v-model.lazy="searchTerm"
                @click="searchTerm.length >= 2 ? isDropdownOpen = true : {}"
                v-debounce="500"
            >

            <div
                class="absolute top-8 z-10 w-full rounded-lg border bg-white shadow-[0px_0px_0px_0px_rgba(0,0,0,0.10),0px_1px_3px_0px_rgba(0,0,0,0.10),0px_5px_5px_0px_rgba(0,0,0,0.09),0px_12px_7px_0px_rgba(0,0,0,0.05),0px_22px_9px_0px_rgba(0,0,0,0.01),0px_34px_9px_0px_rgba(0,0,0,0.00)] dark:border-gray-800 dark:bg-gray-900 sm:top-10"
                v-if="isDropdownOpen"
            >
                <!-- Search Tabs -->
                <div class="flex border-b text-xs text-gray-600 dark:border-gray-800 dark:text-gray-300 sm:text-sm">
                    <div
                        class="cursor-pointer p-2 hover:bg-gray-100 dark:hover:bg-gray-950 sm:p-4"
                        :class="{ 'border-b-2 border-blue-600': activeTab == tab.key }"
                        v-for="tab in tabs"
                        @click="activeTab = tab.key; search();"
                    >
                        @{{ tab.title }}
                    </div>
                </div>

                <!-- Searched Results -->
                <template v-if="activeTab == 'skills'">
                    <template v-if="isLoading">
                        <div class="grid gap-2 p-4">
                            <div class="shimmer h-12 rounded"></div>
                            <div class="shimmer h-12 rounded"></div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="skill.url"
                                class="flex cursor-pointer items-center justify-between gap-2 border-b border-slate-200 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:p-4"
                                v-for="skill in (searchedResults.skills.data || [])"
                            >
                                <div class="grid gap-1">
                                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        @{{ skill.name }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        SKU：@{{ skill.sku }} · 最新版本：@{{ skill.latest_version || '尚未建版本' }}
                                    </p>
                                </div>
                                <span
                                    v-if="skill.version_status"
                                    class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                                >
                                    @{{ skill.version_status }}
                                </span>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                href="{{ route('admin.catalog.skills.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.skills.data && searchedResults.skills.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-skills')".replace(':query', searchTerm).replace(':count', searchedResults.skills.total) }}
                            </a>
                            <a
                                href="{{ route('admin.catalog.skills.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-skills')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'cooperations'">
                    <template v-if="isLoading">
                        <div class="grid gap-2 p-4">
                            <div class="shimmer h-12 rounded"></div>
                            <div class="shimmer h-12 rounded"></div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="coop.url"
                                class="flex cursor-pointer items-center justify-between gap-2 border-b border-slate-200 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:p-4"
                                v-for="coop in (searchedResults.cooperations.data || [])"
                            >
                                <div class="grid gap-1">
                                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        [@{{ coop.type_label }}] @{{ coop.org_name }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        @{{ coop.application_no }} · 联系人：@{{ coop.contact_name }}@{{ coop.followup_person ? ' · 跟进：' + coop.followup_person : '' }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-gray-800 dark:text-gray-300">
                                    @{{ coop.status_label }}
                                </span>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                href="{{ route('admin.customers.cooperations.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.cooperations.data && searchedResults.cooperations.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-cooperations')".replace(':query', searchTerm).replace(':count', searchedResults.cooperations.total) }}
                            </a>
                            <a
                                href="{{ route('admin.customers.cooperations.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-cooperations')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'developers'">
                    <template v-if="isLoading">
                        <div class="grid gap-2 p-4">
                            <div class="shimmer h-12 rounded"></div>
                            <div class="shimmer h-12 rounded"></div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="dev.url"
                                class="flex cursor-pointer items-center justify-between gap-2 border-b border-slate-200 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:p-4"
                                v-for="dev in (searchedResults.developers.data || [])"
                            >
                                <div class="grid gap-1">
                                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                                        @{{ dev.name }}（@{{ dev.dev_type_label }}）
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        @{{ dev.email }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                    @{{ dev.realname_label }}
                                </span>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                href="{{ route('admin.customers.developers.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.developers.data && searchedResults.developers.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-developers')".replace(':query', searchTerm).replace(':count', searchedResults.developers.total) }}
                            </a>
                            <a
                                href="{{ route('admin.customers.developers.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-developers')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'products'">
                    <template v-if="isLoading">
                        <x-admin::shimmer.header.mega-search.products />
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="'{{ route('admin.catalog.products.edit', ':id') }}'.replace(':id', product.id)"
                                class="flex cursor-pointer justify-between gap-2 border-b border-slate-300 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:gap-2.5 sm:p-4"
                                v-for="product in searchedResults.products.data"
                            >
                                <!-- Left Information -->
                                <div class="flex gap-2 sm:gap-2.5">
                                    <!-- Image -->
                                    <div
                                        class="relative h-10 max-h-10 w-full max-w-10 overflow-hidden rounded sm:h-[60px] sm:max-h-[60px] sm:max-w-[60px]"
                                        :class="{'overflow-hidden rounded border border-dashed border-gray-300 dark:border-gray-800 dark:mix-blend-exclusion dark:invert': ! product.images.length}"
                                    >
                                        <template v-if="! product.images.length">
                                            <img src="{{ bagisto_asset('images/product-placeholders/front.svg') }}" class="h-full w-full object-cover">
                                        
                                            <p class="absolute bottom-0.5 w-full text-center text-[4px] font-semibold text-gray-400 sm:bottom-1.5 sm:text-[6px]">
                                                @lang('admin::app.catalog.products.edit.types.grouped.image-placeholder')
                                            </p>
                                        </template>

                                        <template v-else>
                                            <img :src="product.images[0].url" class="h-full w-full object-cover">
                                        </template>
                                    </div>

                                    <!-- Details -->
                                    <div class="grid place-content-start gap-1 sm:gap-1.5">
                                        <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 sm:text-base">
                                            @{{ product.name }}
                                        </p>

                                        <p class="text-xs text-gray-500 sm:text-sm">
                                            @{{ "@lang('admin::app.components.layouts.header.mega-search.sku')".replace(':sku', product.sku) }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Right Information -->
                                <div class="grid place-content-center gap-1 text-right">
                                    <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 sm:text-base">
                                        @{{ product.formatted_price }}
                                    </p>
                                </div>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                :href="'{{ route('admin.catalog.products.index') }}?search=:query'.replace(':query', searchTerm)"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.products.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-products')".replace(':query', searchTerm).replace(':count', searchedResults.products.meta.total) }}
                            </a>

                            <a
                                href="{{ route('admin.catalog.products.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-products')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'orders'">
                    <template v-if="isLoading">
                        <x-admin::shimmer.header.mega-search.orders />
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="'{{ route('admin.sales.orders.view', ':id') }}'.replace(':id', order.id)"
                                class="grid cursor-pointer place-content-start gap-1 border-b border-slate-300 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:gap-1.5 sm:p-4"
                                v-for="order in searchedResults.orders.data"
                            >
                                <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 sm:text-base">
                                    #@{{ order.increment_id }}
                                </p>

                                <p class="text-xs text-gray-500 dark:text-gray-300 sm:text-sm">
                                    @{{ order.formatted_created_at + ', ' + order.status_label + ', ' + order.customer_full_name }}
                                </p>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                :href="'{{ route('admin.sales.orders.index') }}?search=:query'.replace(':query', searchTerm)"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.orders.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-orders')".replace(':query', searchTerm).replace(':count', searchedResults.orders.total) }}
                            </a>

                            <a
                                href="{{ route('admin.sales.orders.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-orders')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'categories'">
                    <template v-if="isLoading">
                        <x-admin::shimmer.header.mega-search.categories />
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="'{{ route('admin.catalog.categories.edit', ':id') }}'.replace(':id', category.id)"
                                class="cursor-pointer border-b p-3 text-xs font-semibold text-gray-600 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-gray-950 sm:p-4 sm:text-sm"
                                v-for="category in searchedResults.categories.data"
                            >
                                @{{ category.name }}
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                :href="'{{ route('admin.catalog.categories.index') }}?search=:query'.replace(':query', searchTerm)"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.categories.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-categories')".replace(':query', searchTerm).replace(':count', searchedResults.categories.total) }}
                            </a>

                            <a
                                href="{{ route('admin.catalog.categories.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-categories')
                            </a>
                        </div>
                    </template>
                </template>

                <template v-if="activeTab == 'customers'">
                    <template v-if="isLoading">
                        <x-admin::shimmer.header.mega-search.customers />
                    </template>

                    <template v-else>
                        <div class="grid max-h-[300px] overflow-y-auto sm:max-h-[400px]">
                            <a
                                :href="'{{ route('admin.customers.customers.view', ':id') }}'.replace(':id', customer.id)"
                                class="grid cursor-pointer place-content-start gap-1 border-b border-slate-300 p-3 last:border-b-0 hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-gray-950 sm:gap-1.5 sm:p-4"
                                v-for="customer in searchedResults.customers.data"
                            >
                                <p class="text-sm font-semibold text-gray-600 dark:text-gray-300 sm:text-base">
                                    @{{ customer.first_name + ' ' + customer.last_name }}
                                </p>

                                <p class="text-xs text-gray-500 sm:text-sm">
                                    @{{ customer.email }}
                                </p>
                            </a>
                        </div>

                        <div class="flex border-t p-2 dark:border-gray-800 sm:p-3">
                            <a
                                :href="'{{ route('admin.customers.customers.index') }}?search=:query'.replace(':query', searchTerm)"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-if="searchedResults.customers.data.length"
                            >
                                @{{ "@lang('admin::app.components.layouts.header.mega-search.explore-all-matching-customers')".replace(':query', searchTerm).replace(':count', searchedResults.customers.total) }}
                            </a>

                            <a
                                href="{{ route('admin.customers.customers.index') }}"
                                class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                                v-else
                            >
                                @lang('admin::app.components.layouts.header.mega-search.explore-all-customers')
                            </a>
                        </div>
                    </template>
                </template>
            </div>
        </div>
    </script>

    <script type="module">
        @php
            $megaTabs = [];

            if (bouncer()->hasPermission('catalog.skills')) {
                $megaTabs['skills'] = [
                    'key'      => 'skills',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.skills'),
                    'endpoint' => route('admin.search.skills'),
                ];
            }

            if (bouncer()->hasPermission('customers.cooperations')) {
                $megaTabs['cooperations'] = [
                    'key'      => 'cooperations',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.cooperations'),
                    'endpoint' => route('admin.search.cooperations'),
                ];
            }

            if (bouncer()->hasPermission('customers.developers')) {
                $megaTabs['developers'] = [
                    'key'      => 'developers',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.developers'),
                    'endpoint' => route('admin.search.developers'),
                ];
            }

            if (bouncer()->hasPermission('catalog.products')) {
                $megaTabs['products'] = [
                    'key'      => 'products',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.products'),
                    'endpoint' => route('admin.catalog.products.search'),
                ];
            }

            if (bouncer()->hasPermission('sales.orders')) {
                $megaTabs['orders'] = [
                    'key'      => 'orders',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.orders'),
                    'endpoint' => route('admin.sales.orders.search'),
                ];
            }

            if (bouncer()->hasPermission('catalog.categories')) {
                $megaTabs['categories'] = [
                    'key'      => 'categories',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.categories'),
                    'endpoint' => route('admin.catalog.categories.search'),
                ];
            }

            if (bouncer()->hasPermission('customers.customers')) {
                $megaTabs['customers'] = [
                    'key'      => 'customers',
                    'title'    => trans('admin::app.components.layouts.header.mega-search.customers'),
                    'endpoint' => route('admin.customers.customers.search'),
                ];
            }

            $defaultMegaTab = array_key_first($megaTabs) ?: 'products';
        @endphp

        app.component('v-mega-search', {
            template: '#v-mega-search-template',

            data() {
                return {
                    activeTab: @json($defaultMegaTab),

                    isDropdownOpen: false,

                    tabs: @json($megaTabs),

                    isLoading: false,

                    searchTerm: '',

                    searchedResults: {
                        skills: { data: [], total: 0 },
                        cooperations: { data: [], total: 0 },
                        developers: { data: [], total: 0 },
                        products: { data: [], meta: { total: 0 } },
                        orders: { data: [], total: 0 },
                        categories: { data: [], total: 0 },
                        customers: { data: [], total: 0 }
                    },
                }
            },

            watch: {
                searchTerm: function(newVal, oldVal) {
                    this.search()
                }
            },

            created() {
                window.addEventListener('click', this.handleFocusOut);
            },

            beforeDestroy() {
                window.removeEventListener('click', this.handleFocusOut);
            },

            methods: {
                search() {
                    if (! this.tabs[this.activeTab]) {
                        return;
                    }

                    if (this.searchTerm.length <= 1) {
                        this.searchedResults[this.activeTab] = { data: [], total: 0, meta: { total: 0 } };

                        this.isDropdownOpen = false;

                        return;
                    }

                    this.isDropdownOpen = true;

                    let self = this;

                    this.isLoading = true;
                    
                    this.$axios.get(this.tabs[this.activeTab].endpoint, {
                            params: {query: this.searchTerm}
                        })
                        .then(function(response) {
                            self.searchedResults[self.activeTab] = response.data;

                            self.isLoading = false;
                        })
                        .catch(function (error) {
                            self.isLoading = false;
                        })
                },

                handleFocusOut(e) {
                    if (! this.$el.contains(e.target)) {
                        this.isDropdownOpen = false;
                    }
                },
            }
        });
    </script>

    <script
        type="text/x-template"
        id="v-notifications-template"
    >
        <x-admin::dropdown position="bottom-{{ core()->getCurrentLocale()->direction === 'ltr' ? 'right' : 'left' }}">
            <!-- Notification Toggle -->
            <x-slot:toggle>
                <span class="relative flex">
                    <span
                        class="icon-notification text-red cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950" 
                        title="@lang('admin::app.components.layouts.header.notifications')"
                    >
                    </span>
                
                    <span
                        class="absolute -top-2 flex h-5 min-w-5 cursor-pointer items-center justify-center rounded-full bg-blue-600 p-1.5 text-[10px] font-semibold leading-[9px] text-white ltr:left-5 rtl:right-5"
                        v-if="totalUnRead"
                    >
                        @{{ totalUnRead }}
                    </span>
                </span>
            </x-slot>

            <!-- Notification Content -->
            <x-slot:content class="min-w-[300px] max-w-[340px] !p-0">
                @if (bouncer()->hasPermission('dashboard.alerts'))
                    <!-- 平台巡检告警 -->
                    <div class="flex items-center justify-between border-b bg-slate-50 px-3 py-2.5 text-sm font-semibold text-gray-700 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-200">
                        <span>平台告警</span>
                        <span
                            v-if="alertsUnread"
                            class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700"
                        >
                            @{{ alertsUnread }} 未读
                        </span>
                    </div>

                    <div class="grid max-h-[200px] overflow-y-auto" v-if="adminAlerts.length">
                        <a
                            href="{{ route('admin.alerts.index') }}"
                            @click.prevent="openAlert(alert)"
                            class="flex flex-col gap-0.5 border-b px-3 py-2.5 last:border-b-0 hover:bg-blue-50/40 dark:border-gray-800 dark:hover:bg-gray-950"
                            v-for="alert in adminAlerts"
                            :key="'alert-' + alert.id"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-gray-800 dark:text-white">
                                    <span v-if="alert.is_unread" class="mr-1 inline-block h-2 w-2 rounded-full bg-red-500"></span>
                                    @{{ alert.title }}
                                </p>
                                <span class="shrink-0 text-[11px] text-gray-400">@{{ alert.created_at }}</span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400" v-if="alert.content">
                                @{{ alert.content }}
                            </p>
                        </a>
                    </div>

                    <div class="border-b px-3 py-2.5 text-xs text-gray-400 dark:border-gray-800" v-else>
                        暂无平台巡检告警
                    </div>

                    <div class="flex justify-between gap-1.5 border-b px-3 py-2 dark:border-gray-800">
                        <a
                            href="{{ route('admin.alerts.index') }}"
                            class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                        >
                            进入告警箱
                        </a>

                        <a
                            class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                            v-if="alertsUnread"
                            @click="readAllAlerts()"
                        >
                            全部告警已读
                        </a>
                    </div>
                @endif

                <!-- Header -->
                <div class="border-b p-3 text-sm font-semibold text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    @lang('admin::app.notifications.title', ['read' => 0])
                </div>

                <!-- Content -->
                <div class="grid max-h-[200px] overflow-y-auto" v-if="notifications?.length">
                    <a
                        class="flex items-start gap-1.5 border-b p-3 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
                        v-for="notification in notifications"
                        :href="'{{ route('admin.notification.viewed_notification', ':orderId') }}'.replace(':orderId', notification.order_id)"
                    >
                        <!-- Notification Icon -->
                        <span
                            v-if="notification.order.status in notificationStatusIcon"
                            class="h-fit"
                            :class="notificationStatusIcon[notification.order.status]"
                        >
                        </span>

                        <div class="grid">
                            <!-- Order Id & Status -->
                            <p class="text-gray-800 dark:text-white">
                                #@{{ notification.order.id }}
                                @{{ orderTypeMessages[notification.order.status] }}
                            </p>

                            <!-- Created Date In humand Readable Format -->
                            <p class="text-xs text-gray-600 dark:text-gray-300">
                                @{{ notification.order.datetime }}
                            </p>
                        </div>
                    </a>
                </div>

                <div class="px-3 py-2.5 text-xs text-gray-400" v-else>
                    暂无未读订单通知
                </div>

                <!-- Footer -->
                <div class="flex h-[47px] justify-between gap-1.5 border-t px-6 py-4 dark:border-gray-800">
                    <a
                        href="{{ route('admin.notification.index') }}"
                        class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                    >
                        @lang('admin::app.notifications.view-all')
                    </a>

                    <a
                        class="cursor-pointer text-xs font-semibold text-blue-600 transition-all hover:underline"
                        v-if="notifications?.length"
                        @click="readAll()"
                    >
                        @lang('admin::app.notifications.read-all')
                    </a>
                </div>
            </x-slot>
        </x-admin::dropdown>
    </script>

    <script type="module">
        app.component('v-notifications', {
            template: '#v-notifications-template',

                props: [
                    'getReadAllUrl',
                    'readAllTitle',
                ],

                data() {
                    return {
                        notifications: [],

                        adminAlerts: [],

                        alertsUnread: 0,

                        orderUnread: 0,

                        ordertype: {
                            pending: {
                                icon: 'icon-information',
                                message: "@lang('admin::app.notifications.order-status-messages.pending-payment')"
                            },

                            processing: {
                                icon: 'icon-processing',
                                message: "@lang('admin::app.notifications.order-status-messages.processing')",
                            },

                            canceled: {
                                icon: 'icon-cancel-1',
                                message: "@lang('admin::app.notifications.order-status-messages.canceled')"
                            },

                            completed: {
                                icon: 'icon-done',
                                message: "@lang('admin::app.notifications.order-status-messages.completed')"
                            },

                            closed: {
                                icon: 'icon-cancel-1',
                                message: "@lang('admin::app.notifications.order-status-messages.closed')"
                            },

                            pending_payment: {
                                icon: "icon-information",
                                message: "@lang('admin::app.notifications.order-status-messages.pending-payment')"
                            },
                        },

                        totalUnRead: 0,

                        orderTypeMessages: {
                        {{ \Webkul\Sales\Models\Order::STATUS_PENDING }}: "@lang('admin::app.notifications.order-status-messages.pending')",
                        {{ \Webkul\Sales\Models\Order::STATUS_CANCELED }}: "@lang('admin::app.notifications.order-status-messages.canceled')",
                        {{ \Webkul\Sales\Models\Order::STATUS_CLOSED }}: "@lang('admin::app.notifications.order-status-messages.closed')",
                        {{ \Webkul\Sales\Models\Order::STATUS_COMPLETED }}: "@lang('admin::app.notifications.order-status-messages.completed')",
                        {{ \Webkul\Sales\Models\Order::STATUS_PROCESSING }}: "@lang('admin::app.notifications.order-status-messages.processing')",
                        {{ \Webkul\Sales\Models\Order::STATUS_PENDING_PAYMENT }}: "@lang('admin::app.notifications.order-status-messages.pending-payment')",
                        }
                    }
                },

                computed: {
                    notificationStatusIcon() {
                        return {
                            pending: 'icon-information rounded-full bg-amber-100 text-2xl text-amber-600 dark:!text-amber-600',
                            closed: 'icon-repeat rounded-full bg-red-100 text-2xl text-red-600 dark:!text-red-600',
                            completed: 'icon-done rounded-full bg-blue-100 text-2xl text-blue-600 dark:!text-blue-600',
                            canceled: 'icon-cancel-1 rounded-full bg-red-100 text-2xl text-red-600 dark:!text-red-600',
                            processing: 'icon-sort-right rounded-full bg-green-100 text-2xl text-green-600 dark:!text-green-600',
                        };
                    },
                },

                mounted() {
                    this.getNotification();
                },

                methods: {
                    getNotification() {
                        this.$axios.get('{{ route('admin.notification.get_notification') }}', {
                                params: {
                                    limit: 5,
                                    read: 0
                                }
                            })
                            .then((response) => {
                                this.notifications = response.data.search_results?.data || [];

                                this.adminAlerts = response.data.admin_alerts || [];

                                this.alertsUnread = response.data.alerts_unread || 0;

                                this.orderUnread = response.data.order_unread || 0;

                                this.totalUnRead = response.data.combined_unread ?? response.data.total_unread ?? 0;
                            })
                            .catch(error => console.log(error))
                    },

                    openAlert(alert) {
                        if (! alert) {
                            return;
                        }

                        if (! alert.is_unread || ! alert.read_url) {
                            window.location.href = alert.index_url || '{{ route('admin.alerts.index') }}';

                            return;
                        }

                        this.$axios.post(alert.read_url)
                            .catch(() => {})
                            .finally(() => {
                                window.location.href = alert.index_url || '{{ route('admin.alerts.index') }}';
                            });
                    },

                    readAllAlerts() {
                        this.$axios.post('{{ route('admin.alerts.read-all') }}')
                            .then(() => {
                                this.getNotification();

                                this.$emitter.emit('add-flash', { type: 'success', message: '平台未读告警已全部标记已读。' });
                            })
                            .catch(() => {});
                    },

                    readAll() {
                        this.$axios.post('{{ route('admin.notification.read_all') }}')
                            .then((response) => {
                                this.notifications = response.data.search_results.data;

                                this.orderUnread = response.data.total_unread || 0;

                                this.totalUnRead = (response.data.total_unread || 0) + (this.alertsUnread || 0);

                            this.$emitter.emit('add-flash', { type: 'success', message: response.data.success_message });
                        })
                        .catch((error) => {});
                },
            },
        });
    </script>

    <script
        type="text/x-template"
        id="v-dark-template"
    >
        <div class="flex">
            <span
                class="cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-gray-100 dark:hover:bg-gray-950"
                :class="[isDarkMode ? 'icon-light' : 'icon-dark']"
                @click="toggle"
            ></span>
        </div>
    </script>

    <script type="module">
        app.component('v-dark', {
            template: '#v-dark-template',

            data() {
                return {
                    isDarkMode: {{ request()->cookie('dark_mode') ?? 0 }},

                    logo: "{{ bagisto_asset('images/logo.svg') }}",

                    dark_logo: "{{ bagisto_asset('images/dark-logo.svg') }}",
                };
            },

            methods: {
                toggle() {
                    this.isDarkMode = parseInt(this.isDarkModeCookie()) ? 0 : 1;

                    var expiryDate = new Date();

                    expiryDate.setMonth(expiryDate.getMonth() + 1);

                    document.cookie = 'dark_mode=' + this.isDarkMode + '; path=/; expires=' + expiryDate.toGMTString();

                    document.documentElement.classList.toggle('dark', this.isDarkMode === 1);

                    if (this.isDarkMode) {
                        this.$emitter.emit('change-theme', 'dark');

                        document.getElementById('logo-image').src = this.dark_logo;
                    } else {
                        this.$emitter.emit('change-theme', 'light');

                        document.getElementById('logo-image').src = this.logo;
                    }
                },

                isDarkModeCookie() {
                    const cookies = document.cookie.split(';');

                    for (const cookie of cookies) {
                        const [name, value] = cookie.trim().split('=');

                        if (name === 'dark_mode') {
                            return value;
                        }
                    }

                    return 0;
                },
            },
        });
    </script>
@endpushOnce