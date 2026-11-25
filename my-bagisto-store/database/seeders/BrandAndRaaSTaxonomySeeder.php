<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Shop\Services\Catalog\CatalogBrandService;

/**
 * 幂等初始化 RaaS 分类树（`tob -> rent/buy`、`skill`、`accessory`）、
 * `robot_type` 属性与初始 TOP 20 机器人品牌。
 */
class BrandAndRaaSTaxonomySeeder extends Seeder
{
    public function run(): void
    {
        app(CatalogBrandService::class)->installRaaSTaxonomy(true);
    }
}
