<x-admin::layouts>
    <x-slot:title>开发者邀请码</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">开发者邀请码</p>
    </div>

    <x-admin::flash />

    {{-- 口径必须写在最显眼处：邀请码不是准入闸门 --}}
    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">邀请码不是准入闸门</p>
        <p class="mt-1 leading-relaxed">
            邀请码只负责三件事：<b>落地路由</b>（带对方进入开发者入驻）、<b>归属</b>（把入驻主体记到这张码名下）、
            <b>来源留痕</b>（事后能查到是谁带进来的）。
        </p>
        <p class="mt-1 leading-relaxed">
            持码者<b>仍需实名与协议</b>（完成实名审核并签署开发者入驻协议）才能完成入驻 ——
            平台<b>不会</b>因为持有邀请码而跳过实名或协议环节。
        </p>
    </div>

    {{-- 发码：只发一次性码（max_uses = 1 固定，UI 不暴露多用途） --}}
    <div class="mb-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-1 font-semibold text-gray-800 dark:text-white">生成邀请码（一次性）</p>
        <p class="mb-3 text-xs text-gray-500">
            每次生成一张仅可使用一次的新码，有效期默认 7 天。生成后请复制并直接发给对方；
            列表里也会一直显示明文码，便于重发。
        </p>

        <form
            method="POST"
            action="{{ route('admin.customers.developer-invites.store') }}"
            class="grid items-end gap-3 md:grid-cols-3"
        >
            @csrf

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">备注（发给谁 / 用途，可选）</span>
                <input
                    type="text"
                    name="note"
                    maxlength="191"
                    value="{{ old('note') }}"
                    placeholder="例如：发给某某机器人公司-张工"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
                @error('note')
                    <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                @enderror
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">有效期</span>
                <select
                    name="expires_in_days"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
                    @foreach ([7 => '7 天（默认）', 30 => '30 天', 90 => '90 天', 0 => '不过期'] as $days => $label)
                        <option value="{{ $days }}" @selected((string) old('expires_in_days', 7) === (string) $days)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('expires_in_days')
                    <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                @enderror
            </label>

            <div>
                <button type="submit" class="primary-button">生成一次性邀请码</button>
            </div>
        </form>
    </div>

    <x-admin::datagrid :src="route('admin.customers.developer-invites.index')" />
</x-admin::layouts>
