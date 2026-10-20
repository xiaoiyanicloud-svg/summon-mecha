<?php

namespace Webkul\Shop\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Webkul\Shop\Services\Ecosystem\PublicContentQuery;
use Webkul\Shop\Services\Ecosystem\PublicEventQuery;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;
use Webkul\Shop\Services\Ecosystem\PublicSiteUrl;

/**
 * G4-04 · sitemap.xml。
 *
 * 只包含当前公开的活动/资讯；草稿、待审、暂缓、撤稿、筛选页、收藏、Admin 预览
 * 一律不进入。kill switch 命中时返回 503，sitemap 开关关闭时 404。
 *
 * 单次上限来自 `ecosystem_g4.sitemap.max_urls`。超限时记 error 并截断 ——
 * 不静默假装完整，也不一次输出无限条拖垮进程。
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly PublicReadGate $gate,
        private readonly PublicEventQuery $events,
        private readonly PublicContentQuery $contents,
        private readonly PublicSiteUrl $siteUrl,
    ) {}

    public function index()
    {
        if (! (bool) config('ecosystem_g4.sitemap.enabled', false)) {
            abort(404);
        }

        try {
            $this->gate->assertAvailable();
        } catch (PublicReadUnavailableException $exception) {
            return response('', 503, ['Cache-Control' => 'no-store']);
        }

        $max = max(1, (int) config('ecosystem_g4.sitemap.max_urls', 5000));

        $entries = [];

        if ((bool) config('ecosystem.events.public_enabled', false)) {
            foreach ($this->events->sitemapRows($max + 1) as $event) {
                $entries[] = [
                    'loc'     => $this->siteUrl->absolute('/events/'.$event->id),
                    'lastmod' => $event->published_at?->copy()->utc()->format('Y-m-d'),
                ];
            }
        }

        if ((bool) config('ecosystem.content.public_enabled', false)) {
            foreach ($this->contents->sitemapRows($max + 1) as $post) {
                $entries[] = [
                    'loc'     => $this->siteUrl->absolute('/content/'.$post->slug),
                    'lastmod' => ($post->first_published_at ?? $post->published_at)?->copy()->utc()->format('Y-m-d'),
                ];
            }
        }

        if (count($entries) > $max) {
            Log::error('ecosystem.sitemap.truncated', [
                'max_urls' => $max,
                'found'    => count($entries),
            ]);

            $entries = array_slice($entries, 0, $max);
        }

        return response()
            ->view('shop::ecosystem.sitemap', ['entries' => $entries])
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'no-store, private');
    }
}
