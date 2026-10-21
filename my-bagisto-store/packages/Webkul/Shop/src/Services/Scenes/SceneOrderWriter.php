<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;

/** 仅在转换事务内适配现有 Bagisto 仓储；金额始终由冻结报价以整数分推导。 */
class SceneOrderWriter
{
    public function __construct(protected SceneSkuService $skus, protected SceneOrderWriteContext $context) {}

    public static function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public function prepare(object $q, object $request, int $channelId): array
    {
        $channel = SceneWorkflow::requireRow('channels', $channelId);
        $customer = SceneWorkflow::requireRow('customers', (int) $request->customer_id);
        if ($q->currency !== 'CNY' || core()->getBaseCurrencyCode() !== $q->currency || (int) $q->total_minor + (int) $q->deposit_minor > 9999999999) {
            throw new SceneWorkflowException('SCENE_ORDER_CURRENCY_UNSUPPORTED', '币种或金额超出当前订单适配范围。', 409);
        }
        $revision = DB::table('scene_request_revisions')->where('request_id', $request->id)->where('revision_no', $request->revision_no)->first();
        $facts = SceneWorkflow::unpack($revision->facts);
        $contactRow = DB::table('scene_request_contacts')->where('request_id', $request->id)->first();
        $contact = $contactRow ? SceneWorkflow::unpack(Crypt::decryptString($contactRow->encrypted_contact)) : [];
        if (empty($contact['name']) || empty($contact['phone']) || empty($facts['city']) || empty($facts['site']) || empty($customer->email)) {
            throw new SceneWorkflowException('SCENE_ORDER_CONTACT_REQUIRED', '订单需要本需求已确认的联系人、交付地点和登录客户。', 409);
        }
        $addressColumn = DB::connection()->getSchemaBuilder()->hasColumn('addresses', 'address') ? 'address' : 'address1';
        $address = [$addressColumn => $facts['site'], 'country' => 'CN', 'state' => '', 'city' => $facts['city'], 'postcode' => '',
            'phone' => $contact['phone'], 'email' => $customer->email, 'first_name' => $contact['name'], 'last_name' => ''];
        $base = ['customer_id' => $customer->id, 'customer_type' => Customer::class, 'customer_email' => $customer->email,
            'customer_first_name' => $contact['name'], 'customer_last_name' => '', 'is_guest' => false,
            'channel_id' => $channelId, 'channel_name' => $channel->name ?? $channel->code, 'channel_type' => \Webkul\Core\Models\Channel::class,
            'base_currency_code' => $q->currency, 'channel_currency_code' => $q->currency, 'order_currency_code' => $q->currency,
            'shipping_method' => '', 'shipping_title' => '', 'shipping_amount' => '0.00', 'base_shipping_amount' => '0.00',
            'payment' => ['method' => 'cashondelivery', 'method_title' => '线下商务（收款以复核凭证为准）'],
            'billing_address' => $address + ['address_type' => 'order_billing'], 'shipping_address' => $address + ['address_type' => 'order_shipping']];
        $groups = ['plan' => [], 'skill' => []];
        $components = SceneWorkflow::unpack($q->proposal_snapshot)['components'];
        foreach (DB::table('scene_quote_items')->where('quote_id', $q->id)->orderBy('price_item_id')->lockForUpdate()->get() as $line) {
            $s = SceneWorkflow::unpack($line->snapshot);
            [$mapping, $product] = $this->skus->resolve((int) $line->price_item_id, $channelId);
            $kind = $mapping->mode === 'skill' ? 'skill' : 'plan';
            $component = collect($components)->first(fn ($c) => $c['selected'] && $c['price_code'] === $s['code']);
            $count = $kind === 'skill' ? (int) $line->quantity : 1;
            for ($i = 0; $i < $count; $i++) {
                $quantity = $kind === 'skill' ? 1 : (int) $line->quantity;
                $raw = (int) $s['unit_minor'] * (int) $line->billing_units * $quantity;
                $discount = intdiv((int) $s['discount_minor'], $count) + ($i < (int) $s['discount_minor'] % $count ? 1 : 0);
                $tax = intdiv((int) $s['tax_minor'], $count) + ($i < (int) $s['tax_minor'] % $count ? 1 : 0);
                $additional = ['scene' => ['protocol' => 'scene-sc3-v1', 'request_id' => (int) $request->id, 'request_revision_no' => (int) $request->revision_no,
                    'quote_id' => (int) $q->id, 'quote_hash' => $q->content_hash, 'terms_hash' => $q->terms_hash,
                    'quote_line_id' => (int) $line->id, 'line_snapshot' => $s, 'billing_units' => (int) $line->billing_units,
                    'sku_mapping_id' => (int) $mapping->id, 'schedule' => SceneWorkflow::unpack($q->schedule)]];
                if ($kind === 'skill') {
                    if (empty($component['skill_version_id'])) {
                        throw new SceneWorkflowException('SCENE_SKILL_VERSION_REQUIRED', '技能子单须冻结正式方案中的技能版本。', 409);
                    }
                    $additional['skill'] = ['device_id' => null, 'version_id' => (int) $component['skill_version_id']];
                }
                $item = ['product_id' => (int) $product->id, 'product_type' => get_class($product), 'type' => $product->type, 'sku' => $product->sku,
                    'name' => $s['name'], 'qty_ordered' => $quantity, 'additional' => $additional,
                    'price' => self::money((int) $s['unit_minor'] * (int) $line->billing_units), 'base_price' => self::money((int) $s['unit_minor'] * (int) $line->billing_units),
                    'total' => self::money($raw), 'base_total' => self::money($raw), 'discount_amount' => self::money($discount), 'base_discount_amount' => self::money($discount),
                    'tax_amount' => self::money($tax), 'base_tax_amount' => self::money($tax), 'tax_percent' => ((int) $s['tax_bps']) / 100,
                    'price_incl_tax' => self::unitIncludingTax($raw + $tax, $quantity), 'base_price_incl_tax' => self::unitIncludingTax($raw + $tax, $quantity),
                    'total_incl_tax' => self::money($raw + $tax), 'base_total_incl_tax' => self::money($raw + $tax)];
                $groups[$kind][] = [$item, $raw, $discount, $tax];
            }
        }
        if (! $groups['plan']) {
            throw new SceneWorkflowException('SCENE_SERVICE_SKU_REQUIRED', '场景项目至少需要一条实际服务费用；纯技能购买请走技能商城。', 409);
        }
        $orders = [];
        $total = 0;
        foreach ($groups as $kind => $lines) {
            if (! $lines) {
                continue;
            }
            $raw = array_sum(array_column($lines, 1));
            $discount = array_sum(array_column($lines, 2));
            $tax = array_sum(array_column($lines, 3));
            $qty = array_sum(array_column(array_column($lines, 0), 'qty_ordered'));
            $orders[$kind] = $base + ['order_type' => $kind, 'total_item_count' => count($lines), 'total_qty_ordered' => $qty,
                'grand_total' => self::money($raw - $discount + $tax), 'base_grand_total' => self::money($raw - $discount + $tax),
                'sub_total' => self::money($raw), 'base_sub_total' => self::money($raw),
                'discount_amount' => self::money($discount), 'base_discount_amount' => self::money($discount),
                'tax_amount' => self::money($tax), 'base_tax_amount' => self::money($tax), 'items' => array_column($lines, 0)];
            $total += $raw - $discount + $tax;
        }
        if ($total !== (int) $q->total_minor + (int) $q->deposit_minor) {
            throw new SceneWorkflowException('SCENE_ORDER_TOTAL_MISMATCH', '拆单合计与客户接受的服务费及押金不一致。', 409);
        }

        return $orders;
    }

    public function create(array $data): object
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('场景建单必须由转换事务管理。');
        }

        return $this->context->run($data, fn () => app(SceneOrderRepository::class)->create($data));
    }

    private static function unitIncludingTax(int $minor, int $quantity): string
    {
        $scaled = intdiv($minor * 100 + intdiv($quantity, 2), $quantity);

        return intdiv($scaled, 10000).'.'.str_pad((string) ($scaled % 10000), 4, '0', STR_PAD_LEFT);
    }
}
