<x-admin::layouts>
    <x-slot:title>编辑赛事</x-slot:title>

    <x-admin::form :action="route('admin.catalog.events.update', $event->id)">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">编辑赛事</p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.catalog.events.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存</button>
            </div>
        </div>

        <div class="mt-3.5 grid gap-4 xl:grid-cols-2">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label class="required">赛事名称</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="name" rules="required" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label class="required">状态</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="select" name="status">
                        <option value="registering">报名中</option>
                        <option value="ongoing">进行中</option>
                        <option value="upcoming">即将开始</option>
                        <option value="ended">已结束</option>
                    </x-admin::form.control-group.control>
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>类型</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="type" placeholder="格斗竞技 / 竞速赛 / 展览…" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>时间</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="date" placeholder="2026年10月1日 8:00-16:00" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>城市</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="city" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>地点</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="location" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>主办方</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="organizer" />
                </x-admin::form.control-group>
            </div>

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>热度 (0-100)</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="hot" value="60" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>参赛队伍数</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="teams" value="0" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>图标 emoji</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="icon" value="🏆" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>排序权重(大在前)</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="text" name="sort" value="0" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>奖项设置</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="prize" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>赛程安排</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="schedule" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>往届回顾</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="history" />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>赛事简介</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="detail" />
                </x-admin::form.control-group>
            </div>
        </div>
    </x-admin::form>
</x-admin::layouts>
