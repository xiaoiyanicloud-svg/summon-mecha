<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Services\Ecosystem\CompetitionAppealService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;

/**
 * G3-09 · 申诉 API（必须登录；只允许本人提交/查看，规则限定队长）。
 */
class CompetitionAppealController extends APIController
{
    public function __construct(private readonly CompetitionAppealService $appeals) {}

    public function index(): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        return $this->ok([
            'appeals' => $this->appeals->listForCustomer($customer)
                ->map(fn ($appeal): array => $this->appeals->toCustomerDto($appeal))
                ->values()
                ->all(),
        ]);
    }

    public function show(int $appealId): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        try {
            return $this->ok($this->appeals->toCustomerDto(
                $this->appeals->findForCustomer($customer, $appealId),
            ));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'result_id'         => ['required', 'integer', 'min:1'],
            'result_version_id' => ['nullable', 'integer', 'min:1'],
            'reason'            => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '申诉参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $result = CompetitionResult::query()->find((int) $request->input('result_id'));

        if ($result === null) {
            return $this->fail(ApiErrorCode::COMPETITION_RESULT_NOT_FOUND, '成绩记录不存在。', 404);
        }

        $versionId = $request->filled('result_version_id')
            ? (int) $request->input('result_version_id')
            : (int) ($result->published_version_id ?? 0);

        try {
            $appeal = $this->appeals->submit(
                (int) $result->getKey(),
                $versionId,
                $customer,
                (string) $request->input('reason'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok($this->appeals->toCustomerDto($appeal));
    }

    public function withdraw(Request $request, int $appealId): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'expected_row_version' => ['required', 'integer', 'min:0'],
            'reason'               => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '撤回参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $appeal = $this->appeals->findForCustomer($customer, $appealId);
            $appeal = $this->appeals->withdraw(
                $appeal,
                $customer,
                (int) $request->input('expected_row_version'),
                (string) $request->input('reason', ''),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok($this->appeals->toCustomerDto($appeal));
    }

    public function uploadEvidence(Request $request, int $appealId): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '请选择证据文件。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $appeal = $this->appeals->findForCustomer($customer, $appealId);
            $attachment = $this->appeals->addEvidence($appeal, $customer, $request->file('file'));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'id'            => (int) $attachment->getKey(),
            'original_name' => (string) $attachment->original_name,
            'mime'          => (string) $attachment->mime,
            'byte_size'     => (int) $attachment->byte_size,
            'download_url'  => '/api/customer/competition/appeal-attachments/'.(int) $attachment->getKey(),
        ]);
    }

    public function downloadEvidence(int $attachmentId): StreamedResponse|JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        try {
            $file = $this->appeals->openEvidenceForCustomer($customer, $attachmentId);
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->stream($file);
    }

    /**
     * @param  array{stream: resource, mime: string, name: string, size: int}  $file
     */
    private function stream(array $file): StreamedResponse
    {
        $name = rawurlencode((string) $file['name']);

        return response()->stream(static function () use ($file): void {
            fpassthru($file['stream']);

            if (is_resource($file['stream'])) {
                fclose($file['stream']);
            }
        }, 200, [
            'Content-Type'           => 'application/octet-stream',
            'Content-Length'         => (string) $file['size'],
            'Content-Disposition'    => "attachment; filename*=UTF-8''".$name,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store',
        ]);
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
