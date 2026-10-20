<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionShowcaseService;

/**
 * G3-11 · 队长授权与技能提审 API（必须登录且为当前队长）。
 */
class CompetitionShowcaseController extends APIController
{
    public function __construct(private readonly CompetitionShowcaseService $showcases) {}

    public function index(): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        return $this->ok([
            'showcases' => $this->showcases->listForCaptain($customer)
                ->map(fn ($showcase): array => $this->showcases->customerDto($showcase))
                ->values()
                ->all(),
        ]);
    }

    public function consent(Request $request, int $showcaseId): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'consent'              => ['required', 'boolean'],
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '授权参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $showcase = $this->showcases->findForCaptain($customer, $showcaseId);
            $showcase = $this->showcases->setConsent(
                $showcase,
                $customer,
                (bool) $request->boolean('consent'),
                (int) $request->input('expected_row_version'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok($this->showcases->customerDto($showcase));
    }

    public function requestSkillReview(Request $request, int $showcaseId): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'skill_version_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '请选择要提审的技能版本。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $showcase = $this->showcases->findForCaptain($customer, $showcaseId);
            $result = $this->showcases->requestSkillReview(
                $showcase,
                $customer,
                (int) $request->input('skill_version_id'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        if (! $result['ok']) {
            return $this->fail(
                $result['code'] !== '' ? $result['code'] : ApiErrorCode::COMPETITION_SHOWCASE_STATE,
                $result['message'] !== '' ? $result['message'] : '技能提审未通过。',
                422,
            );
        }

        return $this->ok($result);
    }

    private function competitionFail(CompetitionException $exception): JsonResponse
    {
        return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
    }

    private function customer(): ?Customer
    {
        $customer = auth()->guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }
}
