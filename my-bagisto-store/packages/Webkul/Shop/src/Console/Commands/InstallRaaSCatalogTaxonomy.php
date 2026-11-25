<?php

namespace Webkul\Shop\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Shop\Services\Catalog\CatalogBrandService;

/**
 * RaaS 目录树与品牌体系安装/对齐工具（幂等，默认 dry-run）。
 *
 * 用法：
 *   php artisan catalog:install-raas-taxonomy         # 只读预览变更
 *   php artisan catalog:install-raas-taxonomy --apply # 写入 tob -> (rent, buy)、robot_type 属性与 TOP 20 品牌
 */
class InstallRaaSCatalogTaxonomy extends Command
{
    protected $signature = 'catalog:install-raas-taxonomy {--apply : 真正写入数据库；不加参数只做 dry-run}';

    protected $description = '安装或对齐 租售TOB(tob)->租赁(rent)/购买(buy) 分类树、机器人形态属性及品牌库（默认 dry-run）';

    public function handle(CatalogBrandService $brands): int
    {
        $apply = (bool) $this->option('apply');
        $result = $brands->installRaaSTaxonomy($apply);

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (! $apply) {
            $this->warn('当前为 dry-run，未修改数据库；确认后请附加 --apply 执行。');
        }

        return self::SUCCESS;
    }
}
