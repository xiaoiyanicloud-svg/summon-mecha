<x-admin::layouts>
    <x-slot:title>举报处置台</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">举报处置台</p>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这是技能上线之后，唯一的用户侧违规通道</p>
        <p class="mt-1 leading-relaxed">
            「先审后发 + 内容安全」管的是<b>上线前</b>。技能一旦下发、在真实机器上跑起来，
            用户能按的最后一个刹车就是举报。用户可在 App 里举报<b>技能</b>或<b>评价</b>。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 处置<b>不会</b>自动改变技能的上架状态 —— "举报成立"与"这个技能该不该卖"是两个判断。
            要下架请去<b>审核台</b>（驳回/改状态）或<b>熔断台</b>，那里的理由与留痕各自独立。
            <br>
            ⚠ 同一用户对同一对象，在上一单处置完之前<b>只会有一条</b>举报（去重）；
            处置完（本篇结案）后可以再举报 —— 对象没改好就该能再报。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.skill-reports.index')" />
</x-admin::layouts>
