<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 去伪：把 CMS 静态页里**安装器自带的占位文案**换成诚实的"尚未定稿"说明。
 *
 * 背景：`/page/privacy-policy` 这类页面是**公开可达且返回 200** 的。而它此前的正文是
 * Bagisto 安装器塞进去的占位串 —— 简体中文下显示为 `隱私政策頁面內容`（还是繁体）。
 *
 * 为什么这算"假真相"而不是"暂时难看"：
 *   一个标题写着《隐私政策》、HTTP 200、正文非空的页面，看起来**像"有这份文件"**。
 *   将来有人（合作方、审核方、用户）核"这个平台有没有隐私政策"，
 *   看到的是"有"。而实际上一个字都没有。
 *   本项目的红线是"没查 ≠ 合规" —— 那么"没写 ≠ 有"同样成立。
 *
 * 做法：换成一句**明确说明正文尚未定稿**的短说明。不编造条款，也不留一个像条款的空壳。
 *
 * ⚠ 只处理 `zh_CN`。原因写在这里，免得下次被当成漏做：
 *   站内语言切换器确实能切到另外 20 个 locale（它们都是安装器批量生成的），
 *   但把同一段说明**翻译成 20 种语言由我来写**，就等于用我编的译文冒充本地化文案 ——
 *   这正是本项目一贯拒绝的那类"不可验证的产出"。
 *   正文定稿后是在 CMS 后台按语言逐条录入的，那时各 locale 自然补齐。
 *   所以这里只保证**产品实际使用的语言（APP_LOCALE=zh_CN）**不再说谎。
 *
 * ⚠ 覆盖条件：**只覆盖仍然是安装器占位串的行**（正文里含 "頁面內容"）。
 *   已经被人编辑过的页面一律不动 —— 迁移不能把真实内容冲掉。
 *
 * ⚠ **必须清一次响应缓存**：本项目装了 spatie/laravel-responsecache，策略是
 *   `CacheAllSuccessfulGetRequests` —— 这些页面**返回的是缓存里的 HTML**。
 *   只改库不清缓存的话，页面上仍然是旧占位文案（本机实测踩过：`migrate` 跑完
 *   页面纹丝不动，`responsecache:clear` 之后才变）。所以下面显式清一次。
 */
return new class extends Migration
{
    /**
     * url_key => [简体标题, 文档名]
     */
    private const PAGES = [
        'privacy-policy'   => ['隐私政策', '隐私政策'],
        'terms-of-use'     => ['使用条款', '使用条款'],
        'terms-conditions' => ['条款和条件', '条款和条件'],
        'return-policy'    => ['退货政策', '退货政策'],
        'refund-policy'    => ['退款政策', '退款政策'],
        'payment-policy'   => ['付款政策', '付款政策'],
        'shipping-policy'  => ['运输政策', '运输政策'],
        'customer-service' => ['客户服务', '客户服务'],
        'about-us'         => ['关于我们', '关于我们'],
        'whats-new'        => ['最新消息', '最新消息'],
    ];

    /**
     * 这几份属于需要律师/合规定稿的文件，说明里要讲清"为什么现在是空的"。
     */
    private const LEGAL_DOCS = [
        'privacy-policy', 'terms-of-use', 'terms-conditions',
        'return-policy', 'refund-policy', 'payment-policy', 'shipping-policy',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cms_page_translations')) {
            return;
        }

        foreach (self::PAGES as $urlKey => [$title, $docName]) {
            DB::table('cms_page_translations')
                ->where('locale', 'zh_CN')
                ->where('url_key', $urlKey)
                // 只动还是占位串的行：迁移不许覆盖已经有人写过的内容
                ->where('html_content', 'like', '%頁面內容%')
                ->update([
                    'page_title'   => $title,
                    'html_content' => in_array($urlKey, self::LEGAL_DOCS, true)
                        ? $this->legalNotice($docName)
                        : $this->genericNotice($docName),
                ]);
        }

        $this->flushResponseCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('cms_page_translations')) {
            return;
        }

        // 还原安装器占位串（繁体），与迁移前一致
        $installerTitles = [
            'privacy-policy'   => '隱私政策',
            'terms-of-use'     => '使用條款',
            'terms-conditions' => '條款和條件',
            'return-policy'    => '退貨政策',
            'refund-policy'    => '退款政策',
            'payment-policy'   => '付款政策',
            'shipping-policy'  => '運送政策',
            'customer-service' => '客戶服務',
            'about-us'         => '關於我們',
            'whats-new'        => '最新消息',
        ];

        foreach ($installerTitles as $urlKey => $title) {
            DB::table('cms_page_translations')
                ->where('locale', 'zh_CN')
                ->where('url_key', $urlKey)
                // 只还原本迁移写进去的说明，不碰别的
                ->where(function ($q) {
                    $q->where('html_content', 'like', '%正文尚未定稿%')
                        ->orWhere('html_content', 'like', '%内容尚未填写%');
                })
                ->update([
                    'page_title'   => $title,
                    'html_content' => '<div class="static-container"><div class="mb-5">'.$title.'頁面內容</div></div>',
                ]);
        }

        $this->flushResponseCache();
    }

    /**
     * 页面是走 spatie/laravel-responsecache 出的，改库不等于改页面 —— 必须显式失效。
     */
    private function flushResponseCache(): void
    {
        if (! class_exists(\Spatie\ResponseCache\Facades\ResponseCache::class)) {
            return;
        }

        try {
            \Spatie\ResponseCache\Facades\ResponseCache::clear();
        } catch (\Throwable $e) {
            // 缓存清不掉不该让迁移失败（内容已经写进去了，清缓存可在部署步骤里重做），
            // 但必须在日志里留下痕迹 —— 否则"页面没变"会变成一个查不出原因的怪现象。
            logger()->error('CMS 占位页迁移：响应缓存清理失败，页面可能仍显示旧内容。', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function legalNotice(string $docName): string
    {
        return '<div class="static-container">'
            .'<div class="mb-5"><strong>本页正文尚未定稿</strong></div>'
            .'<div class="mb-5">《'.$docName.'》需要由律师定稿并完成合规审查后才会发布，目前正文尚未定稿，因此本页不展示任何条款内容。</div>'
            .'<div class="mb-5">在正文发布之前，请不要把本页当作任何权利或义务的依据。需要了解相关事项，请通过客服联系我们。</div>'
            .'</div>';
    }

    private function genericNotice(string $docName): string
    {
        return '<div class="static-container">'
            .'<div class="mb-5"><strong>本页内容尚未填写</strong></div>'
            .'<div class="mb-5">「'.$docName.'」的内容尚未填写。写好之后会更新在本页。</div>'
            .'</div>';
    }
};
