<x-admin::layouts>
    <x-slot:title>评价处置台</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">评价处置台</p>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">评价是公开内容：先审后发，机器先扫、人再判</p>
        <p class="mt-1 leading-relaxed">
            用户买过技能后可以评（评分 / 标题 / 正文 / 最多 3 张图），一人一技能一条。
            提交时走内容安全扫描，机器判定直接决定<b>能不能被看见</b>：
            <b>可见</b>（扫过没问题）、<b>不可见</b>（命中违禁词）、<b>待人工看</b>（<b>根本没扫成</b>）。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 「待人工看」里很大一部分是<b>「未扫成」</b>（词表缺失 / 驱动不可用）。
            那不是"干净"，是"不知道" —— 请按未确认处理。
            <br>
            ⚠ 词表会<b>误判</b>。被机器拦下的评价是<b>留档</b>的（不是丢弃），
            复核后确认误判可以恢复显示 —— 但<b>必须写理由</b>。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.skill-review-moderation.index')" />
</x-admin::layouts>
