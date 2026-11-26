<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Webkul\Shop\Services\SkillCompile\GaSupportMatrixService;

class RobotSupportMatrixController extends APIController
{
    public function __construct(protected GaSupportMatrixService $matrix) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->matrix->build(), '机器人支持矩阵');
    }
}