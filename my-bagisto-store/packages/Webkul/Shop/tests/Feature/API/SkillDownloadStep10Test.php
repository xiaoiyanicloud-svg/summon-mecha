<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('Step 10 tests require the dedicated summon_mecha_step10_test database.');
    }
    config(['skill_delivery.asset_disk' => 'step10_download_regression']);
    Storage::fake('step10_download_regression');
});

/** Standalone fixtures: no other test file needs to be loaded. */
function step10DownloadFixture(): array
{
    $token = bin2hex(random_bytes(8));
    $customer = Customer::create([
        'first_name' => 'Step10', 'last_name' => 'Download',
        'email'      => 'step10-download-'.$token.'@example.com',
        'password'   => bcrypt('step10-isolated-test'),
        'channel_id' => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1,
    ]);
    $familyId = DB::table('attribute_families')->value('id');
    if (! $familyId) {
        throw new LogicException('The isolated test database must contain an attribute family.');
    }
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'S10-D-'.$token, 'type' => 'simple', 'attribute_family_id' => $familyId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $version = SkillVersion::create([
        'product_id' => $productId, 'version' => '1.0.0',
        'status'     => SkillVersion::STATUS_PUBLISHED, 'released_at' => now(),
    ]);
    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'S10D'.$token, 'customer_id' => $customer->id,
        'status'       => 'processing', 'grand_total' => 100,
        'created_at'   => now(), 'updated_at' => now(),
    ]);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id'    => $orderId, 'product_id' => $productId, 'type' => 'simple',
        'name'        => 'Step 10 download package', 'sku' => 'S10-DI-'.$token,
        'qty_ordered' => 1, 'price' => 100, 'total' => 100,
        'created_at'  => now(), 'updated_at' => now(),
    ]);
    $ledger = [
        'cycle_id'      => 'step10-download-cycle', 'state' => 'pending', 'attempts' => 2,
        'lease_token'   => 'keep-this-token', 'lease_until' => now()->addMinute()->timestamp,
        'next_retry_at' => now()->addMinutes(5)->timestamp, 'reason_code' => 'TECHNICAL_EXCEPTION',
    ];
    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id, 'order_id' => $orderId, 'order_item_id' => $itemId,
        'product_id'       => $productId, 'skill_version_id' => $version->id,
        'delivery_channel' => 'app', 'status' => SkillEntitlement::STATUS_GRANTED,
        'license_key'      => SkillEntitlement::newLicenseKey(), 'activations' => 2, 'max_activations' => 5,
        'granted_at'       => now(),
        'meta'             => ['download_count' => 4, 'delivery_retry' => $ledger, 'unrelated' => ['keep' => true]],
    ]);
    $payload = "Step 10 private package\n".$token;
    $asset = SkillAsset::create([
        'skill_version_id' => $version->id, 'carrier_type' => SkillAsset::CARRIER_FILE,
        'file_path'        => 'step10/'.$token.'.zip', 'file_name' => 'step10.zip',
        'file_size'        => strlen($payload), 'sort_order' => 0,
    ]);
    Storage::disk(SkillAsset::disk())->put($asset->file_path, $payload);

    return compact('customer', 'version', 'entitlement', 'asset', 'payload');
}

it('Step 10 streams the private package and changes only download statistics', function () {
    $fixture = step10DownloadFixture();
    $entitlement = $fixture['entitlement'];
    $before = $entitlement->fresh();
    actingAs($fixture['customer'], 'customer');

    getJson(route('shop.api.skills.download', [$entitlement->id, $fixture['asset']->id]))
        ->assertOk()->assertStreamedContent($fixture['payload']);

    $after = $entitlement->fresh();
    expect($after->meta['download_count'])->toBe(5)
        ->and($after->meta['last_download_asset'])->toBe($fixture['asset']->id)
        ->and($after->meta['last_download_at'])->not->toBeEmpty()
        ->and($after->meta['delivery_retry'])->toEqual($before->meta['delivery_retry'])
        ->and($after->meta['unrelated'])->toEqual($before->meta['unrelated'])
        ->and($after->activations)->toBe(2)
        ->and($after->max_activations)->toBe(5)
        ->and($after->status)->toBe($before->status)
        ->and(OperationLog::where('action', 'skill.download')->where('target_id', $after->id)->count())->toBe(1);
});

it('Step 10 rejects invalid downloads without changing metadata or activation usage', function ($scenario, $status, $code) {
    $fixture = step10DownloadFixture();
    $entitlement = $fixture['entitlement'];
    $asset = $fixture['asset'];
    $assetId = $asset->id;
    switch ($scenario) {
        case 'revoked':
            $entitlement->update(['status' => SkillEntitlement::STATUS_REVOKED]);
            break;
        case 'expired':
            $entitlement->update(['expires_at' => now()->subMinute()]);
            break;
        case 'unpaid':
            DB::table('orders')->where('id', $entitlement->order_id)->update(['status' => 'pending']);
            break;
        case 'closed':
            DB::table('orders')->where('id', $entitlement->order_id)->update(['status' => 'closed']);
            break;
        case 'trial without order':
            $entitlement->update(['order_id' => null, 'order_item_id' => null,
                'meta'                       => array_merge($entitlement->meta, ['trial' => true]), 'expires_at' => now()->addMinutes(20)]);
            break;
        case 'missing asset':
            $assetId = 0;
            break;
        case 'non-file asset':
            $asset->update(['carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG]);
            break;
        case 'missing file':
            Storage::disk(SkillAsset::disk())->delete($asset->file_path);
            break;
        case 'another version':
            $other = SkillVersion::create(['product_id' => $entitlement->product_id, 'version' => '2.0.0']);
            $asset->update(['skill_version_id' => $other->id]);
            break;
        case 'another customer':
            $other = Customer::create([
                'first_name' => 'Other', 'last_name' => 'Download owner',
                'email'      => 'step10-other-download-'.bin2hex(random_bytes(8)).'@example.com',
                'password'   => bcrypt('step10-isolated-test'),
                'channel_id' => core()->getDefaultChannel()->id, 'status' => 1, 'is_verified' => 1,
            ]);
            $entitlement->update(['customer_id' => $other->id]);
            break;
    }
    $before = $entitlement->fresh();
    actingAs($fixture['customer'], 'customer');

    getJson(route('shop.api.skills.download', [$entitlement->id, $assetId]))
        ->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('code', $code);

    $after = $entitlement->fresh();
    expect($after->meta)->toEqual($before->meta)
        ->and($after->activations)->toBe($before->activations)
        ->and($after->status)->toBe($before->status)
        ->and(OperationLog::where('action', 'skill.download')->where('target_id', $after->id)->count())->toBe(0);
})->with([
    ['revoked', 403, ApiErrorCode::ENTITLEMENT_REVOKED],
    ['expired', 403, ApiErrorCode::ENTITLEMENT_REVOKED],
    ['unpaid', 409, ApiErrorCode::ENTITLEMENT_UNPAID],
    ['closed', 409, ApiErrorCode::ENTITLEMENT_UNPAID],
    ['trial without order', 409, ApiErrorCode::ENTITLEMENT_UNPAID],
    ['missing asset', 404, ApiErrorCode::SKILL_ASSET_NOT_FOUND],
    ['non-file asset', 404, ApiErrorCode::SKILL_ASSET_NOT_FOUND],
    ['missing file', 404, ApiErrorCode::SKILL_ASSET_FILE_MISSING],
    ['another version', 404, ApiErrorCode::SKILL_ASSET_NOT_FOUND],
    ['another customer', 404, ApiErrorCode::ENTITLEMENT_NOT_FOUND],
]);

it('Step 10 preserves download kill-switch strength semantics', function ($action, $blocked) {
    $fixture = step10DownloadFixture();
    $entitlement = $fixture['entitlement'];
    SkillBlocklist::create([
        'skill_id'   => $entitlement->product_id, 'action' => $action,
        'status'     => SkillBlocklist::STATUS_ACTIVE, 'reason' => 'Step 10 isolated regression',
        'created_by' => 'step10-test',
    ]);
    $before = $entitlement->fresh();
    actingAs($fixture['customer'], 'customer');

    $response = getJson(route('shop.api.skills.download', [$entitlement->id, $fixture['asset']->id]));
    if ($blocked) {
        $response->assertStatus(409)->assertJsonPath('code', ApiErrorCode::SKILL_BLOCKED);
        expect($entitlement->fresh()->meta)->toEqual($before->meta);
    } else {
        $response->assertOk()->assertStreamedContent($fixture['payload']);
        expect($entitlement->fresh()->meta['download_count'])->toBe(5);
    }
    expect($entitlement->fresh()->meta['delivery_retry'])->toEqual($before->meta['delivery_retry']);
})->with([
    [SkillBlocklist::ACTION_BLOCK_NEW, false],
    [SkillBlocklist::ACTION_BLOCK_ALL, true],
    [SkillBlocklist::ACTION_FORCE_UNINSTALL, true],
    [SkillBlocklist::ACTION_FORCE_ROLLBACK, true],
]);
