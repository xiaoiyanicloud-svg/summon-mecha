<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把「使用条款」这一页的名字对齐成「用户协议」。
 *
 * 起因：`2026_10_14_000008` 把 CMS 占位页去伪时，正文里嵌的文档名取自 Bagisto 的
 * `url_key`（`terms-of-use` → 「使用条款」）。但平台自己的文档从头到尾管这份文件叫
 * **用户协议**（见 `docs/skill-remaining-closure.md` 的 E6、`docs/skill-p0-board.md` 的 T0 项）。
 *
 * 于是一份文件有了两个名字：App 入口写「用户协议」，点进去页头写「使用条款」，
 * 正文里还写着「《使用条款》需要由律师定稿」。**同一件事两个出处，必然分叉** ——
 * 这次分叉是上一轮迁移自己造出来的（名字不是人写的，是 url_key 直译的），所以在源头修掉它，
 * 而不是靠前端再写一遍说明去弥合。
 *
 * ⚠ 与 000008 同样的克制：**只动仍然是那一次迁移写下的值**。
 *   `page_title` 已不是「使用条款」、或正文里已不含「《使用条款》」的行一律不碰 ——
 *   迁移不许覆盖后来有人写过的内容。
 */
return new class extends Migration
{
    private const URL_KEY = 'terms-of-use';

    private const OLD_TITLE = '使用条款';

    private const NEW_TITLE = '用户协议';

    public function up(): void
    {
        $this->rename(self::OLD_TITLE, self::NEW_TITLE);
    }

    public function down(): void
    {
        $this->rename(self::NEW_TITLE, self::OLD_TITLE);
    }

    private function rename(string $from, string $to): void
    {
        if (! Schema::hasTable('cms_page_translations')) {
            return;
        }

        $rows = DB::table('cms_page_translations')
            ->where('locale', 'zh_CN')
            ->where('url_key', self::URL_KEY)
            ->where('page_title', $from)
            ->where('html_content', 'like', '%《'.$from.'》%')
            ->get(['id', 'html_content']);

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            DB::table('cms_page_translations')
                ->where('id', $row->id)
                ->update([
                    'page_title'   => $to,
                    'html_content' => str_replace('《'.$from.'》', '《'.$to.'》', $row->html_content),
                ]);
        }

        // 网页端那条路由挂的是 spatie/laravel-responsecache（7 天）—— 改库不等于改页面
        $this->flushResponseCache();
    }

    private function flushResponseCache(): void
    {
        if (! class_exists(\Spatie\ResponseCache\Facades\ResponseCache::class)) {
            return;
        }

        try {
            \Spatie\ResponseCache\Facades\ResponseCache::clear();
        } catch (\Throwable $e) {
            logger()->error('CMS 页面改名迁移：响应缓存清理失败，页面可能仍显示旧标题。', [
                'exception' => $e->getMessage(),
            ]);
        }
    }
};
