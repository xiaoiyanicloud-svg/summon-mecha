<?php

namespace Webkul\Shop\Services\Ecosystem;

/**
 * G4-04 · 公开站点绝对地址。
 *
 * canonical / OG / JSON-LD / sitemap 必须使用同一个 base，不能一处用请求 host、
 * 另一处用配置值 —— 否则反代、H5 与 SSR 多入口会出现互相矛盾的 canonical。
 *
 * 生产必须配置 `ecosystem_g4.ssr.base_url`；留空只在本机验证时回退到请求根。
 */
class PublicSiteUrl
{
    public function base(): string
    {
        $configured = trim((string) config('ecosystem_g4.ssr.base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(url('/'), '/');
    }

    public function absolute(string $path): string
    {
        if ($path === '') {
            return $this->base();
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return $this->base().'/'.ltrim($path, '/');
    }

    public function siteName(): string
    {
        return (string) config('ecosystem_g4.ssr.site_name', '召唤机甲');
    }
}
