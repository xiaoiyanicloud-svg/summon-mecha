<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\RegistrationContactVault;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;

/**
 * G2-C · 联系人密文密钥轮换（T56）。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }

    config([
        'ecosystem_registration.enabled' => true,
        'ecosystem.events.registration_enabled' => true,
        'ecosystem.events.registration_write_verified' => true,
    ]);
});

it('G2-C · 旧 APP_KEY 可读，轮换后只依赖当前密钥', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scopeId = Fixtures::scope($event['id'], [
        'status' => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity' => 5,
    ]);
    $scope = EventRegistrationScope::findOrFail($scopeId);

    $customer = Customer::factory()->create([
        'email' => 'g2-key-'.bin2hex(random_bytes(4)).'@example.test',
        'status' => 1,
    ]);

    $result = app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, [
        'request_key' => 'key-'.bin2hex(random_bytes(4)),
        'form_version' => (int) $scope->form_version,
        'privacy_version' => (string) $scope->privacy_version,
        'consent' => true,
        'name' => '当前姓名',
        'phone' => '13900000000',
        'city' => '上海',
    ]);

    $attemptId = (int) $result['attempt']->id;

    // 模拟历史 APP_KEY 加密的行。
    $oldKey = 'base64:'.base64_encode(random_bytes(32));
    $oldEncrypter = new Encrypter(base64_decode(substr($oldKey, 7)), 'aes-256-cbc');

    DB::table('event_registration_attempts')->where('id', $attemptId)->update([
        'contact_name_enc'  => $oldEncrypter->encryptString('旧姓名'),
        'contact_phone_enc' => $oldEncrypter->encryptString('13900000000'),
        'note_enc'          => null,
    ]);

    config(['ecosystem_registration.previous_app_keys' => [$oldKey]]);

    $vault = app(RegistrationContactVault::class);
    expect($vault->decryptName((string) DB::table('event_registration_attempts')->where('id', $attemptId)->value('contact_name_enc')))
        ->toBe('旧姓名');

    // dry-run -> 可轮换
    Artisan::call('ecosystem:rotate-registration-contact');
    expect(Artisan::output())->toContain('可轮换');

    // apply -> 用当前密钥重新加密
    Artisan::call('ecosystem:rotate-registration-contact', ['--apply' => true]);

    $row = DB::table('event_registration_attempts')->where('id', $attemptId)->first();

    // 不再依赖旧 key。
    config(['ecosystem_registration.previous_app_keys' => []]);

    expect(Crypt::decryptString($row->contact_name_enc))->toBe('旧姓名');
    expect(Crypt::decryptString($row->contact_phone_enc))->toBe('13900000000');
    expect($row->contact_phone_hash)->toBe($vault->hashPhone('13900000000'));
    expect($vault->decryptName($row->contact_name_enc))->toBe('旧姓名');
});
