<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Services\Catalog\CatalogBrandService;

/**
 * 前台 RaaS 品牌与机器人形态字典接口。
 */
class BrandController extends APIController
{
    public function index(CatalogBrandService $service): JsonResponse
    {
        return response()->json(['data' => $service->publicMetadata()]);
    }
}
