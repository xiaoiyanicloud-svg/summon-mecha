<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Resources\Ecosystem\CompetitionResource;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionSubmission;
use Webkul\Shop\Models\CompetitionTeamInvite;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\CompetitionEntryService;
use Webkul\Shop\Services\Ecosystem\CompetitionException;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamRegistrationService;
use Webkul\Shop\Services\Ecosystem\CompetitionTeamService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;

/**
 * 队伍 API（G3-03 / G3-04）。
 *
 * 身份只认登录客户；队伍可见性由 `competition_team_members` 重新核验，
 * 不信任客户端传入的 team_id。跨队伍访问与不存在统一 404。
 */
class CompetitionTeamController extends APIController
{
    public function __construct(
        private readonly CompetitionTeamService $teams,
        private readonly CompetitionTeamRegistrationService $registrations,
        private readonly CompetitionEntryService $entries,
    ) {}

    public function index(): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $data = [];

        foreach ($this->teams->membershipsFor($customer) as $membership) {
            $team = $membership->team;

            if ($team === null) {
                continue;
            }

            $entry = $this->entries->forTeam($team);
            $submission = $entry !== null
                ? CompetitionSubmission::query()->where('entry_id', $entry->getKey())->first()
                : null;

            $data[] = CompetitionResource::team($team, $membership, $entry, $submission);
        }

        return $this->ok($data);
    }

    public function show(int $id): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $team = $this->teams->accessibleTeam($id, $customer);

        if ($team === null) {
            return $this->teamNotFound();
        }

        $viewer = CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('customer_id', (int) $customer->getKey())
            ->first();

        $entry = $this->entries->forTeam($team);
        $submission = $entry !== null
            ? CompetitionSubmission::query()->where('entry_id', $entry->getKey())->first()
            : null;

        return $this->ok(CompetitionResource::team(
            $team,
            $viewer,
            $entry,
            $submission,
            $this->pendingInviteCount($team, $viewer),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'track_id' => ['required', 'integer', 'min:1'],
            'name'     => ['required', 'string', 'min:2', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '队伍参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $track = CompetitionTrack::query()->find((int) $request->input('track_id'));

        if ($track === null) {
            return $this->fail(ApiErrorCode::COMPETITION_TRACK_NOT_FOUND, '赛项不存在。', 404);
        }

        try {
            $team = $this->teams->createTeam($customer, $track, (string) $request->input('name'));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        $viewer = CompetitionTeamMember::query()
            ->where('team_id', $team->getKey())
            ->where('customer_id', (int) $customer->getKey())
            ->first();

        return $this->ok(CompetitionResource::team($team, $viewer), '队伍已创建。');
    }

    public function invite(Request $request, int $id): JsonResponse
    {
        [$customer, $team, $error] = $this->ownedTeam($id);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '邀请参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $result = $this->teams->issueInvite(
                $team,
                $customer,
                (int) $request->input('expected_row_version'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'token'      => $result['token'],
            'expires_at' => $result['invite']->expires_at?->toIso8601String(),
        ], '邀请码只显示一次，请转交给队员。');
    }

    public function join(Request $request): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '邀请码不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $member = $this->teams->acceptInvite($customer, (string) $request->input('token'));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok([
            'team_id'     => (int) $member->team_id,
            'customer_id' => (int) $member->customer_id,
            'role'        => (string) $member->role,
            'status'      => (string) $member->status,
        ], '已加入队伍。');
    }

    public function decline(Request $request): JsonResponse
    {
        $customer = $this->customer();

        if ($customer === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string', 'max:128'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '邀请码不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $this->teams->declineInvite($customer, (string) $request->input('token'));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok(null, '已拒绝邀请。');
    }

    public function leave(Request $request, int $id): JsonResponse
    {
        [$customer, $team, $error] = $this->ownedTeam($id);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'expected_row_version' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '退出参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $this->teams->leave($team, $customer, (int) $request->input('expected_row_version'));
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok(null, '已退出队伍。');
    }

    public function transfer(Request $request, int $id): JsonResponse
    {
        [$customer, $team, $error] = $this->ownedTeam($id);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'new_captain_customer_id' => ['required', 'integer', 'min:1'],
            'expected_row_version'    => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '移交参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        try {
            $team = $this->teams->transferCaptain(
                $team,
                $customer,
                (int) $request->input('new_captain_customer_id'),
                (int) $request->input('expected_row_version'),
            );
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        }

        return $this->ok(CompetitionResource::team($team), '队长已移交。');
    }

    /**
     * 队伍报名：报名归属队伍，提交人必须是当前队长。
     */
    public function register(Request $request, int $id): JsonResponse
    {
        [$customer, $team, $error] = $this->ownedTeam($id);

        if ($error !== null) {
            return $error;
        }

        $validator = Validator::make($request->all(), [
            'request_key'         => ['required', 'string', 'max:100'],
            'form_version'        => ['required', 'integer', 'min:1'],
            'privacy_version'     => ['required', 'string', 'max:50'],
            'rules_version_id'    => ['nullable', 'integer', 'min:1'],
            'consent'             => ['required', 'accepted'],
            'name'                => ['required', 'string', 'max:50'],
            'phone'               => ['required', 'string', 'max:20'],
            'city'                => ['nullable', 'string', 'max:100'],
            'note'                => ['nullable', 'string', 'max:500'],
            'robot'               => ['sometimes', 'array', 'max:20'],
            'robot.*'             => ['nullable', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return $this->reject(ApiErrorCode::VALIDATION_ERROR, '队伍报名参数不合法。', [
                'errors' => $validator->errors()->toArray(),
            ]);
        }

        $track = CompetitionTrack::query()->find($team->track_id);
        $event = $track !== null
            ? Event::query()->find($track->edition?->event_id)
            : null;

        if ($track === null) {
            return $this->fail(ApiErrorCode::COMPETITION_TRACK_NOT_FOUND, '赛项不存在。', 404);
        }

        if ($event === null) {
            return $this->fail(ApiErrorCode::COMPETITION_NOT_FOUND, '赛事活动不存在。', 404);
        }

        try {
            $result = $this->registrations->submit($event, $track, $team, $customer, $validator->validated());
        } catch (CompetitionException $exception) {
            return $this->competitionFail($exception);
        } catch (RegistrationException $exception) {
            return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
        }

        return $this->ok([
            'public_no'   => (string) $result['registration']->public_no,
            'event_id'    => (int) $result['registration']->event_id,
            'team_id'     => (int) $result['registration']->team_id,
            'status'      => (string) $result['attempt']->status,
            'attempt_id'  => (int) $result['attempt']->getKey(),
            'attempt_no'  => (int) $result['attempt']->attempt_no,
            'entry'       => CompetitionResource::entry($result['entry']),
        ], $result['replayed'] ? '已返回原报名结果。' : '队伍报名已提交。', [
            'replayed' => (bool) $result['replayed'],
        ]);
    }

    /**
     * @return array{0: Customer, 1: \Webkul\Shop\Models\CompetitionTeam, 2: JsonResponse|null}
     */
    private function ownedTeam(int $id): array
    {
        $customer = $this->customer();

        if ($customer === null) {
            return [$customer, null, $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401)];
        }

        $team = $this->teams->accessibleTeam($id, $customer);

        if ($team === null) {
            return [$customer, null, $this->teamNotFound()];
        }

        return [$customer, $team, null];
    }

    private function pendingInviteCount(\Webkul\Shop\Models\CompetitionTeam $team, ?CompetitionTeamMember $viewer): int
    {
        if ($viewer === null || (string) $viewer->role !== CompetitionTeamMember::ROLE_CAPTAIN) {
            return 0;
        }

        return CompetitionTeamInvite::query()
            ->where('team_id', $team->getKey())
            ->where('status', CompetitionTeamInvite::STATUS_PENDING)
            ->count();
    }

    private function competitionFail(CompetitionException $exception): JsonResponse
    {
        return $this->fail($exception->errorCode(), $exception->getMessage(), $exception->httpStatus());
    }

    private function teamNotFound(): JsonResponse
    {
        return $this->fail(ApiErrorCode::COMPETITION_TEAM_NOT_FOUND, '队伍不存在或无权访问。', 404);
    }

    private function customer(): ?Customer
    {
        $customer = auth()->guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }
}
