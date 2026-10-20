<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;

class SceneIntakeClockService
{
    public function __construct(protected SceneWorkflow $flow, protected ScenePolicyService $policies, protected SceneBusinessCalendar $calendar) {}

    /** 与需求创建同事务冻结日历及责任人；无批准日历时不编造到期时间。 */
    public function start(object $request): void
    {
        $policy = $this->policies->effective('service_calendar');
        if (! $policy) {
            DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'calendar:'.$request->id, 'request_id' => $request->id, 'kind' => 'calendar_unconfigured', 'state' => 'open', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return;
        }
        $content = $this->policies->validate('service_calendar', $policy['content'], false);
        DB::table('scene_service_clocks')->insert(['request_id' => $request->id, 'calendar_snapshot' => SceneWorkflow::json($policy),
            'due_at' => $this->calendar->deadline($request->created_at, $content['first_response_minutes'], $content),
            'escalates_at' => $this->calendar->deadline($request->created_at, $content['escalation_minutes'], $content),
            'assignee_id' => $content['owner_id'], 'escalation_assignee_id' => $content['escalation_owner_id'], 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        if (DB::table('admins')->where('id', $content['owner_id'])->where('status', 1)->exists()) {
            DB::table('scene_requests')->where('id', $request->id)->update(['assignee_id' => $content['owner_id']]);
        } else {
            // 人员停用不丢弃客户需求；保留原承诺时限，进入明确的升级分派待办。
            $escalation = DB::table('admins')->where('id', $content['escalation_owner_id'])->where('status', 1)->exists() ? $content['escalation_owner_id'] : null;
            DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => 'unassigned:'.$request->id, 'request_id' => $request->id, 'kind' => 'unassigned_request', 'state' => 'open', 'assignee_id' => $escalation, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        }
    }

    public function routed(int $requestId, int $actor): void
    {
        DB::table('scene_service_clocks')->where('request_id', $requestId)->where('state', 'awaiting_contact')->update(['assignee_id' => $actor, 'updated_at' => now('UTC')]);
        DB::table('scene_operation_todos')->where('request_id', $requestId)->where('state', 'open')->whereIn('kind', ['unassigned_request', 'contact_overdue'])
            ->update(['assignee_id' => $actor, 'updated_at' => now('UTC')]);
    }

    public function contacted(object $request, string $occurred): void
    {
        if ($occurred < $request->created_at) {
            throw new SceneWorkflowException('SCENE_CONTACT_TIME_INVALID', '实际联系时间不能早于本需求受理时间。');
        }
        DB::table('scene_service_clocks')->where('request_id', $request->id)->where('state', 'awaiting_contact')->update(['state' => 'contacted', 'contacted_at' => $occurred, 'updated_at' => now('UTC')]);
        DB::table('scene_operation_todos')->where('request_id', $request->id)->where('state', 'open')->whereIn('kind', ['contact_overdue', 'contact_escalation'])
            ->update(['state' => 'resolved', 'resolution_code' => 'actual_contact_recorded', 'updated_at' => now('UTC')]);
    }

    public function scan(): array
    {
        $count = ['overdue' => 0, 'escalated' => 0];
        DB::table('scene_service_clocks')->where('state', 'awaiting_contact')->where('due_at', '<=', now('UTC'))->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $ref) {
                $change = DB::transaction(function () use ($ref) {
                    $request = SceneWorkflow::requireRow('scene_requests', (int) $ref->request_id);
                    $clock = SceneWorkflow::requireRow('scene_service_clocks', (int) $ref->id);
                    if ($clock->state !== 'awaiting_contact') {
                        return [];
                    }
                    if (in_array($request->state, ['closed', 'cancelled', 'converted'], true)) {
                        DB::table('scene_service_clocks')->where('id', $clock->id)->update(['state' => 'stopped', 'updated_at' => now('UTC')]);
                        DB::table('scene_operation_todos')->where('request_id', $request->id)->where('state', 'open')->whereIn('kind', ['contact_overdue', 'contact_escalation', 'calendar_unconfigured'])
                            ->update(['state' => 'resolved', 'resolution_code' => 'request_closed_or_converted', 'updated_at' => now('UTC')]);

                        return [];
                    }
                    $result = [];
                    foreach (['overdue', 'escalated'] as $stage) {
                        if ($stage === 'escalated' && ($clock->escalated_at || $clock->escalates_at > now('UTC'))) {
                            continue;
                        }
                        $key = 'contact-'.$stage.':'.$clock->id;
                        $assignee = $stage === 'overdue' ? (int) $clock->assignee_id : (int) $clock->escalation_assignee_id;
                        $inserted = DB::table('scene_operation_todos')->insertOrIgnore(['dedupe_key' => $key, 'request_id' => $request->id, 'kind' => $stage === 'overdue' ? 'contact_overdue' : 'contact_escalation',
                            'state' => 'open', 'assignee_id' => $assignee, 'due_at' => $stage === 'overdue' ? $clock->due_at : $clock->escalates_at, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                        if ($inserted) {
                            $this->flow->record('system', null, 'scene_request', (int) $request->id, (int) $request->row_version, 'scene.request.contact.'.$stage, ['recipient_id' => $assignee]);
                            $result[$stage] = 1;
                        }
                        if ($stage === 'escalated') {
                            DB::table('scene_service_clocks')->where('id', $clock->id)->update(['escalated_at' => now('UTC'), 'updated_at' => now('UTC')]);
                            // 不改派业务所有权；升级责任人和原受理人各自留在对应待办。
                        }
                    }

                    return $result;
                }, 3);
                foreach ($change as $key => $value) {
                    $count[$key] += $value;
                }
            }
        });

        return $count;
    }

    public function customerDto(int $requestId): array
    {
        $clock = DB::table('scene_service_clocks')->where('request_id', $requestId)->first();
        if (! $clock) {
            return ['status' => 'not_committed', 'notice' => '响应时限尚未确认，平台将在受理后与您沟通。'];
        }
        $snapshot = SceneWorkflow::unpack($clock->calendar_snapshot);

        return ['status' => $clock->state, 'due_at' => $clock->due_at, 'timezone' => $snapshot['content']['timezone'],
            'calendar_version' => $snapshot['version'], 'contacted_at' => $clock->contacted_at, 'notice' => '按已冻结工作日历计时；自动提醒不代表已经联系客户。'];
    }
}
