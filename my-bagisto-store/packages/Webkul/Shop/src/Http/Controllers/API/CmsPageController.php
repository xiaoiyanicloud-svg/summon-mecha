<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\CMS\Models\Page;

/**
 * 静态内容页（隐私政策 / 使用条款 / 关于我们 …）—— 给 uni-app 的**只读**出口。
 *
 * ⚠ 这不是一个新的公开面，而是**已经公开的网页路由 `/page/{slug}` 的 JSON 镜像**：
 *   取数条件与 `Shop\Http\Controllers\PageController::view()` 完全一致（当前频道的 CMS 页 +
 *   按 `url_key` 取翻译）。所以这个接口**永远不会比网页多暴露一页** ——
 *   这比维护一份"允许哪些 slug"的白名单更可靠：白名单会漂移，镜像不会。
 *
 * **为什么需要它**：App 里的「隐私政策 / 用户协议」如果在自己的代码里写死正文，
 *   就等于制造了第二个「协议正文出处」—— 律师定稿后改了后台，网页端更新了、
 *   App 还显示旧条款，而没有任何东西能发现这件事。App 必须读同一份内容。
 *
 * ⚠ 刻意**不挂 `cache.response`**：网页端那条路由挂了它（7 天），
 *   所以后台改了 CMS 之后**网页**可能继续显示旧正文，直到缓存被显式清掉。
 *   App 侧不必跟着一起陈旧 —— 这份内容很小、读得也不频繁，直接查库更诚实。
 *
 * ⚠⚠ **这里没有用 `PageRepository`，是刻意的 —— 别把它"修正"回去。**
 *   下面用 `Page::query()` 而不是 `$this->pageRepository->whereHas(...)->whereTranslation(...)`，
 *   因为后者会**把查询条件留在仓储实例上**：同一个容器里连续查两次，
 *   第二次的 SQL 会变成 `url_key = ? and url_key = ?`（两次的 slug 都带上），
 *   必然查不到 → 404。实测证据（同一个 Pest 用例里连查两页）：
 *
 *     select * from cms_pages
 *       where exists (…channels… id = ?) and exists (…cms_page_translations… url_key = ?)
 *         and exists (…channels… id = ?) and exists (…cms_page_translations… url_key = ?)
 *
 *     绑定值 [1,"terms-of-use",1,"privacy-policy"]  → 无解 → null → 404
 *
 *   正常 HTTP 请求各自新建容器，所以**线上目前碰不到**；但测试、队列 worker、
 *   常驻进程里能碰到，而且现象是"第二次查任何一页都 404"，极难定位。
 *   原生 `PageController::view()` 是同样的写法（单请求容器下无碍），别照抄它。
 */
class CmsPageController extends APIController
{
    /**
     * 按 `url_key` 取一页（限当前频道）。
     *
     * 找不到时返回 **404**（`CMS_PAGE_NOT_FOUND`）。网页端在这条路径上会先查
     * `url_rewrite` 做 301/302 兜底；API 不跟这个 —— 返回一个 302 给 App 只会让它
     * 更困惑，而 404 的含义是明确的：平台上没有这一页。
     */
    public function show(string $slug): JsonResponse
    {
        $page = Page::query()
            ->whereHas('channels', function ($query) {
                $query->where('id', core()->getCurrentChannel()->id);
            })
            ->whereTranslation('url_key', $slug)
            ->first();

        if (! $page) {
            return $this->fail(ApiErrorCode::CMS_PAGE_NOT_FOUND, '平台上没有这一页。', 404);
        }

        return response()->json([
            'data' => [
                'slug'   => $slug,
                // 标题也取自后台（不再是前端各写一份）。空标题时如实回 null，
                // 让调用方自己决定回退文案 —— 服务端不替它编一个。
                'title'  => $page->page_title ?: null,
                // 正文原样返回。**当前这几页的内容是"尚未定稿"的说明，不是条款** ——
                // 那是后台的真实内容，API 不做任何美化。
                'html_content' => (string) $page->html_content,

                // ⚠ 这里**刻意没有 updated_at**。
                //
                // 正文存在 `cms_page_translations`，而那张表**根本没有任何时间戳列**
                // （实测：只有 id/page_title/url_key/html_content/meta_*/locale/cms_page_id）。
                // 顺带能拿到的 `cms_pages.updated_at` 是**页面行**的时间，不是正文的：
                // 2026_10_14_000008 那次改写正文只 UPDATE 了 translations，
                // 页面的 updated_at 至今停在 2026-08-25。
                // 把那个日期当"正文更新于"展示出去，就等于告诉用户这份隐私政策是八月写的
                // —— 而它其实是十月改的。**错的日期比没有日期更糟**，故不返回。
                // （真需要"某版正文何时生效"，得先给 translations 加时间戳或走签署留痕，
                //   那是独立的一块，不在这里假装。）
            ],
        ]);
    }
}
