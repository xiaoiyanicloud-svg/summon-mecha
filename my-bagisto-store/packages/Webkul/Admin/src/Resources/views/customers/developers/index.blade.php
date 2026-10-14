<x-admin::layouts>
    <x-slot:title>开发者管理</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">开发者管理</p>
    </div>

    <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">这里的「实名通过」是人工确认，不是身份核验</p>
        <p class="mt-1 leading-relaxed">
            当前入驻表单只收集了<b>联系人姓名与电话</b>，没有身份证/营业执照，也没有接 OCR 或工商核验服务。
            所以审核页上的「通过」意思是：<b>平台看过已提交的信息，人工确认没问题</b> ——
            它<b>不等于</b>完成了实名认证。对外表述请按这个口径来。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-amber-800">
            要做到真正的实名，按依赖顺序需要：① 隐私政策与个人信息保护告知上线；
            ② 收集字段扩展 + 敏感字段加密存储；③ OCR / 工商核验服务商接入。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.customers.developers.index')" />
</x-admin::layouts>
