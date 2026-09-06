<x-admin::layouts>
    <x-slot:title>
        @lang('admin::app.catalog.scenes.create.title')
    </x-slot>

    <!-- Scene Create Form -->
    <x-admin::form
        :action="route('admin.catalog.scenes.store')"
        enctype="multipart/form-data"
    >
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                @lang('admin::app.catalog.scenes.create.title')
            </p>

            <div class="flex items-center gap-x-2.5">
                <!-- Back Button -->
                <a
                    href="{{ route('admin.catalog.scenes.index') }}"
                    class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                >
                    @lang('admin::app.catalog.scenes.create.back-btn')
                </a>

                <!-- Save Button -->
                <button type="submit" class="primary-button">
                    @lang('admin::app.catalog.scenes.create.save-btn')
                </button>
            </div>
        </div>

        <div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">
            <!-- Left Section -->
            <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.catalog.scenes.create.general')
                    </p>

                    <!-- Name -->
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">
                            @lang('admin::app.catalog.scenes.create.name')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            id="name"
                            name="name"
                            rules="required"
                            :value="old('name')"
                            :label="trans('admin::app.catalog.scenes.create.name')"
                            :placeholder="trans('admin::app.catalog.scenes.create.name')"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>

                    <!-- Description -->
                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>
                            @lang('admin::app.catalog.scenes.create.description')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="textarea"
                            class="description"
                            id="description"
                            name="description"
                            :value="old('description')"
                            :label="trans('admin::app.catalog.scenes.create.description')"
                        />

                        <x-admin::form.control-group.error control-name="description" />
                    </x-admin::form.control-group>

                    <!-- Sort (权重：越大越靠前) -->
                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>
                            @lang('admin::app.catalog.scenes.create.sort')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="number" id="sort" name="sort" :value="old('sort')" :placeholder="'0'" />
                        <x-admin::form.control-group.error control-name="sort" />
                    </x-admin::form.control-group>

                    <!-- Banner -->
                    <div class="flex pt-4">
                        <div class="flex w-full flex-col gap-2">
                            <p class="font-medium text-gray-800 dark:text-white">
                                @lang('admin::app.catalog.scenes.create.banner')
                            </p>

                            <p class="text-xs text-gray-500">
                                @lang('admin::app.catalog.scenes.create.banner-size')
                            </p>

                            <!-- 原生 file input：保证文件随表单上传（media.images 组件在部分环境提交不了文件，会“能预览但保存后无图”） -->
                            <input
                                type="file"
                                name="banner_path[]"
                                accept="image/*"
                                class="block w-full cursor-pointer rounded-md border border-gray-300 p-2 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300"
                                onchange="if(this.files && this.files[0]){ var p=this.parentElement.querySelector('img'); if(p){ p.src=URL.createObjectURL(this.files[0]); p.style.display='block'; } }"
                            />

                            <img alt="" style="display:none; margin-top:8px;" class="h-32 w-auto rounded-md object-contain" />
                        </div>
                    </div>

                    <div class="border-t border-gray-200 my-4"></div>

                    <!-- ===== 方案内容 ===== -->
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">
                        @lang('admin::app.catalog.scenes.create.scheme-panel')
                    </p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                @lang('admin::app.catalog.scenes.create.icon')
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="text" id="icon" name="icon" :value="old('icon')" :placeholder="'例如 📦'" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                @lang('admin::app.catalog.scenes.create.bg-color')
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="text" id="bg_color" name="bg_color" :value="old('bg_color')" :placeholder="'例如 #E8F0FE'" />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>
                                @lang('admin::app.catalog.scenes.create.consult-count')
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="number" id="consult_count" name="consult_count" :value="old('consult_count')" :placeholder="'0'" />
                        </x-admin::form.control-group>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.stats-amp')</x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="text" id="stats_amp" name="stats_amp" :value="old('stats_amp')" :placeholder="'例如 +300%'" />
                        </x-admin::form.control-group>
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.stats-roi')</x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="text" id="stats_roi" name="stats_roi" :value="old('stats_roi')" :placeholder="'例如 8个月'" />
                        </x-admin::form.control-group>
                        <x-admin::form.control-group>
                            <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.stats-scene')</x-admin::form.control-group.label>
                            <x-admin::form.control-group.control type="text" id="stats_scene" name="stats_scene" :value="old('stats_scene')" :placeholder="'例如 50+'" />
                        </x-admin::form.control-group>
                    </div>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.tags')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="textarea" id="tags" name="tags" :value="old('tags')" :placeholder="'官方正品'" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.pains')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="textarea" id="pains" name="pains" :value="old('pains')" :placeholder="'💸|人工搬运成本高|机器人替代搬运'" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.benefits')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="textarea" id="benefits" name="benefits" :value="old('benefits')" :placeholder="'⏱️|无人分拣|夜间照样作业'" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.scheme-scenes')</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control type="textarea" id="scheme_scenes" name="scheme_scenes" :value="old('scheme_scenes')" :placeholder="'电商仓配|订单波次自动分拣|效率 +300%'" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label>@lang('admin::app.catalog.scenes.create.robots')</x-admin::form.control-group.label>

                        @forelse($rentProducts as $p)
                            <label class="mr-3 inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="robots[]" value="{{ $p->id }}" @checked(in_array($p->id, old('robots') ?? [])) />
                                {{ $p->name }}
                            </label>
                        @empty
                            <p class="text-sm text-gray-500">暂无可选租用产品（需先建「租用」分类及产品）</p>
                        @endforelse
                    </x-admin::form.control-group>
                </div>
            </div>

            <!-- Right Section -->
            <div class="flex w-[360px] max-w-full flex-col gap-2">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <!-- Status -->
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="font-medium text-gray-800 dark:text-white">
                            @lang('admin::app.catalog.scenes.create.status')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="switch"
                            class="cursor-pointer"
                            name="status"
                            value="1"
                            :label="trans('admin::app.catalog.scenes.create.status')"
                        />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="mt-4">
                        <x-admin::form.control-group.label class="font-medium text-gray-800 dark:text-white">
                            @lang('admin::app.catalog.scenes.create.is_hot')
                        </x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="switch"
                            class="cursor-pointer"
                            name="is_hot"
                            value="1"
                            :label="trans('admin::app.catalog.scenes.create.is_hot')"
                        />
                    </x-admin::form.control-group>
                </div>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
