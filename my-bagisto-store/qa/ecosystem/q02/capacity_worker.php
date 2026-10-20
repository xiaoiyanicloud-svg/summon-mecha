<?php

/**
 * Q-02 · 真实并发报名 worker（独立 PHP 进程）。
 *
 *   php capacity_worker.php <event_id> <customer_id> <request_key> <barrier_file>
 *
 * 两个进程在屏障文件出现后同时进入 RegistrationSubmissionService：
 * 验证容量 1 时只会有一个 confirmed，另一个稳定 REGISTRATION_FULL。
 * 业务失败也以 exit 0 + JSON 返回，由 runner 判定；异常才 exit 1。
 */

require __DIR__.'/../bootstrap.php';

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;

config([
    'ecosystem.events.registration_enabled'        => true,
    'ecosystem.events.registration_write_verified' => true,
    'ecosystem_registration.enabled'               => true,
]);

$eventId = (int) ($argv[1] ?? 0);
$customerId = (int) ($argv[2] ?? 0);
$requestKey = (string) ($argv[3] ?? '');
$barrier = (string) ($argv[4] ?? '');

if ($eventId <= 0 || $customerId <= 0 || $requestKey === '' || $barrier === '') {
    fwrite(STDERR, "usage: capacity_worker.php <event_id> <customer_id> <request_key> <barrier_file>\n");
    exit(1);
}

while (! file_exists($barrier)) {
    usleep(1000);
}

$result = [
    'pid'         => getmypid(),
    'event_id'    => $eventId,
    'customer_id' => $customerId,
    'request_key' => $requestKey,
    'ok'          => false,
];

try {
    $event = Event::query()->findOrFail($eventId);
    $customer = Customer::query()->findOrFail($customerId);

    $submitted = app(RegistrationSubmissionService::class)->submit($event, $customer, [
        'request_key'     => $requestKey,
        'form_version'    => 1,
        'privacy_version' => '2026-10-19',
        'consent'         => true,
        'name'            => 'QA 并发客户',
        'phone'           => '1390000000'.($customerId % 10),
        'city'            => '上海',
    ]);

    $result['ok'] = true;
    $result['registration_id'] = (int) $submitted['registration']->id;
    $result['attempt_id'] = (int) $submitted['attempt']->id;
    $result['status'] = (string) $submitted['attempt']->status;
    $result['public_no'] = (string) $submitted['registration']->public_no;
    $result['replayed'] = (bool) ($submitted['replayed'] ?? false);
} catch (RegistrationException $exception) {
    $result['error_code'] = $exception->errorCode();
    $result['error_message'] = $exception->getMessage();
} catch (Throwable $exception) {
    $result['error_class'] = $exception::class;
    $result['error_message'] = $exception->getMessage();
    qa_json($result);
    exit(1);
}

qa_json($result);
