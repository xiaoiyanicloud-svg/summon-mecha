<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Resources\Ecosystem\CompetitionResource;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmissionVersion;
use Webkul\Shop\Services\Ecosystem\CompetitionAttachmentService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionSubmissionService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamService;

/**
 * 作品 API（G3-05）。
 *
 * 读写都重新核验队伍归属；附件下载只对队伍有效成员开放，
 * 响应强制 attachment + no-store，不暴露私有存储路径。
 */
class CompetitionSubmissionController extends APIController
{
    public function __construct(
        private readonly CompetitionTeamService $teams,
        private readonly CompetitionSubmissionService $submissions,
        private readonly CompetitionAttachmentService $attachments,
    ) {}

    public function workspace(int $teamId): JsonResponse
    {
        [$customer, $team, $entry, $error] = $this->context($teamId);

        if ($error !== null) {
            return $error;
        }

        try {
            $result = $this->submissions->workspace($team, $entry, $customer);
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok(CompetitionResource::submission($result['submission']));
    }

    public function saveDraft(Request $request, int $teamId): JsonResponse
    {
        [$customer, $team, $entry, $error] = $this->context($teamId);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'title'                => ['required', 'string', 'max:160'],
            'summary'              => ['nullable', 'string', 'max:500'],
            'description'          => ['nullable', 'string', 'max:20000'],
            'repo_url'             => ['nullable', 'string', 'max:500'],
            'video_url'            => ['nullable', 'string', 'max:500'],
            'expected_row_version' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '作品草稿参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $version = $this->submissions->saveDraft(
                $team,
                $entry,
                $customer,
                $validator->validated(),
                $request->filled('expected_row_version') ? (int) $request->input('expected_row_version') : null,
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'version_id' => (int) $version->getKey(),
            'version_no' => (int) $version->version_no,
            'status'     => (string) $version->status,
            'submission_row_version' => (int) \Webkul\Shop\Models\CompetitionSubmission::query()
                ->whereKey($version->submission_id)
                ->value('row_version'),
        ], '草稿已保存。');
    }

    public function submit(Request $request, int $teamId): JsonResponse
    {
        [$customer, $team, $entry, $error] = $this->context($teamId);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'expected_row_version' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '提交参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $version = $this->submissions->submit(
                $team,
                $entry,
                $customer,
                $request->filled('expected_row_version') ? (int) $request->input('expected_row_version') : null,
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'version_id' => (int) $version->getKey(),
            'version_no' => (int) $version->version_no,
            'status'     => (string) $version->status,
            'submitted_at' => $version->submitted_at?->toIso8601String(),
        ], '作品已提交。');
    }

    public function uploadAttachment(Request $request, int $teamId): JsonResponse
    {
        [$customer, $team, $entry, $error] = $this->context($teamId);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'version_id' => ['required', 'integer', 'min:1'],
            'file'       => ['required', 'file'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '附件参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $version = CompetitionSubmissionVersion::query()
            ->whereKey((int) $request->input('version_id'))
            ->first();

        if ($version === null) {
            return $this->fail(ApiErrorCode::COMPETITION_SUBMISSION_NOT_FOUND, '作品版本不存在。', 404);
        }

        try {
            $attachment = $this->attachments->upload(
                $team,
                $entry,
                $version,
                $customer,
                $request->file('file'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'id'            => (int) $attachment->getKey(),
            'original_name' => (string) $attachment->original_name,
            'mime'          => (string) $attachment->mime,
            'byte_size'     => (int) $attachment->byte_size,
        ], '附件已上传。');
    }

    public function downloadAttachment(int $attachmentId): StreamedResponse|JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        try {
            $file = $this->attachments->openForMember($customer, $attachmentId);
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        $name = rawurlencode((string) $file['name']);

        return response()->stream(static function () use ($file): void {
            fpassthru($file['stream']);

            if (is_resource($file['stream'])) {
                fclose($file['stream']);
            }
        }, 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Length'      => (string) $file['size'],
            'Content-Disposition' => "attachment; filename*=UTF-8''".$name,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    /**
     * @return array{0: Customer, 1: \Webkul\Shop\Models\CompetitionTeam, 2: CompetitionEntry, 3: JsonResponse|null}
     */
    private function context(int $teamId): array
    {
        $customer = $this->customer();

        if ($customer === null) {
            return [$customer, null, null, $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401)];
        }

        $team = $this->teams->accessibleTeam($teamId, $customer);

        if ($team === null) {
            return [$customer, null, null, $this->fail(ApiErrorCode::COMPETITION_TEAM_NOT_FOUND, '队伍不存在或无权访问。', 404)];
        }

        $entry = CompetitionEntry::query()->where('team_id', $team->getKey())->first();

        if ($entry === null) {
            return [$customer, $team, null, $this->fail(ApiErrorCode::COMPETITION_ENTRY_NOT_FOUND, '队伍尚未报名或参赛条目不存在。', 404)];
        }

        return [$customer, $team, $entry, null];
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
