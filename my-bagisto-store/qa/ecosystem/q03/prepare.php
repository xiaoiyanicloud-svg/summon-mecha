<?php

/**
 * Q-03 · outbox worker 演练夹具。
 *
 * 1) 复用 Q-02 的 prepare（已发布活动 + scope + 两个客户）；
 * 2) 用真实 RegistrationSubmissionService 自动确认一笔报名，
 *    从而产生 `registration.submitted` + `registration.confirmed` 两条 outbox。
 *
 * 输出：fixture + registration_id / attempt_id / attempt_row_version / outbox_ids。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;

config([
    'ecosystem.events.registration_enabled'        => true,
    'ecosystem.events.registration_write_verified' => true,
    'ecosystem_registration.enabled'               => true,
]);

$prepared = qa_spawn(__DIR__.'/../q02/prepare.php', ['10', 'auto']);
$fixture = qa_last_json($prepared['stdout']);

if ($fixture === null || ($fixture['event_id'] ?? 0) <= 0) {
    fwrite(STDERR, "q02 prepare failed: ".$prepared['stderr'].$prepared['stdout']);
    exit(1);
}

$event = Event::query()->findOrFail((int) $fixture['event_id']);
$customer = Customer::query()->findOrFail((int) $fixture['customer_ids'][0]);

$submitted = app(RegistrationSubmissionService::class)->submit($event, $customer, [
    'request_key'     => 'q03-'.$fixture['marker'],
    'form_version'    => 1,
    'privacy_version' => '2026-10-19',
    'consent'         => true,
    'name'            => 'QA outbox customer',
    'phone'           => '13700000001',
    'city'            => '上海',
]);

$registration = $submitted['registration'];
$attempt = $submitted['attempt']->fresh();

$fixture['registration_id'] = (int) $registration->id;
$fixture['attempt_id'] = (int) $attempt->id;
$fixture['attempt_status'] = (string) $attempt->status;
$fixture['attempt_row_version'] = (int) $attempt->row_version;
$fixture['public_no'] = (string) $registration->public_no;
$fixture['outbox_ids'] = DB::table('ecosystem_outbox')
    ->where('aggregate_type', 'registration')
    ->where('aggregate_id', $registration->id)
    ->orderBy('id')
    ->pluck('id')
    ->map(fn ($id) => (int) $id)
    ->all();

qa_json($fixture);
