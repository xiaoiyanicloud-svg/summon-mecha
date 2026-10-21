<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\KillSwitchService;

/** Buyer visibility and purchase eligibility; never applies to owned downloads or Admin. */
class SkillMarketService
{
    public function currentVersions(): Builder
    {
        return DB::table('skill_versions')->select('product_id')->where('status', SkillVersion::STATUS_PUBLISHED)
            ->whereNotExists(function ($newer) {
                $newer->selectRaw('1')->from('skill_versions as newer')
                    ->whereColumn('newer.product_id', 'skill_versions.product_id')
                    ->where('newer.status', SkillVersion::STATUS_PUBLISHED)
                    ->where(function ($order) {
                        $order->whereColumn('newer.released_at', '>', 'skill_versions.released_at')
                            ->orWhere(fn ($dated) => $dated->whereNull('skill_versions.released_at')->whereNotNull('newer.released_at'))
                            ->orWhere(function ($tie) {
                                $tie->where(function ($same) {
                                    $same->whereColumn('newer.released_at', 'skill_versions.released_at')
                                        ->orWhere(fn ($null) => $null->whereNull('newer.released_at')->whereNull('skill_versions.released_at'));
                                })->whereColumn('newer.id', '>', 'skill_versions.id');
                            });
                    });
            });
    }

    public function categoryProducts(): Builder
    {
        return DB::table('product_categories')->select('product_id')
            ->whereIn('category_id', fn ($q) => $q->select('category_id')->from('category_translations')->where('slug', 'skill'));
    }

    protected function publicRows(?string $channelCode = null): Builder
    {
        return DB::table('product_flat')->where('status', 1)->where('visible_individually', 1)
            ->where('locale', app()->getLocale())->where('channel', $channelCode ?? core()->getCurrentChannel()->code)
            ->whereNotNull('name')->whereNotNull('price')->where('price', '>=', 0);
    }

    public function visibleRows(?string $channelCode = null): Builder
    {
        $query = $this->publicRows($channelCode)->whereIn('product_id', $this->categoryProducts())
            ->whereIn('product_id', $this->currentVersions());
        $blocked = app(KillSwitchService::class)->saleBlockedSkillIds();
        if ($blocked) {
            $query->whereNotIn('product_id', $blocked);
        }

        return $query;
    }

    /** A SQL exclusion, applied before pagination; losing the category never turns a skill into hardware. */
    public function unavailableProductIds(): Builder
    {
        $blocked = app(KillSwitchService::class)->saleBlockedSkillIds();

        return DB::table('products')->select('id')->where(function ($q) use ($blocked) {
            $q->where(function ($skills) {
                $skills->where(function ($known) {
                    $known->whereIn('id', $this->categoryProducts())
                        ->orWhereIn('id', DB::table('skill_versions')->select('product_id'));
                })->whereNotIn('id', $this->visibleRows()->select('product_id'));
            });
            if ($blocked) {
                $q->orWhereIn('id', $blocked);
            }
        });
    }

    public function isSkill(int $productId): bool
    {
        return DB::table('skill_versions')->where('product_id', $productId)->exists()
            || $this->categoryProducts()->where('product_id', $productId)->exists();
    }

    public function availability(int $productId, bool $requireSkill = false): ?array
    {
        if (app(KillSwitchService::class)->isBlockedFor($productId, 'sale')) {
            return $this->unavailable(ApiErrorCode::SKILL_BLOCKED, '该技能已被平台下架，暂不可购买。');
        }
        if (! $requireSkill && ! $this->isSkill($productId)) {
            return null;
        }

        return $this->visibleRows()->where('product_id', $productId)->exists()
            ? null : $this->unavailable(ApiErrorCode::SKILL_UNAVAILABLE, '该技能暂未上架或没有可购买的发布版本，请返回技能市场重新选择。');
    }

    /** Caller keeps a transaction open through order creation; sorted product locks match withdrawal/kill switch. */
    public function purchaseProblem(array $ids, ?int $customerId, ?string $declaredType = null): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        foreach ($ids as $id) {
            // Explicit current reads: an older RR snapshot must not hide a withdrawal committed while waiting.
            $category = DB::table('product_categories as pc')->join('category_translations as ct', 'ct.category_id', '=', 'pc.category_id')
                // Current shared reads protect category withdrawal without serializing every skill buyer on the same translation row.
                ->where('pc.product_id', $id)->where('ct.slug', 'skill')->sharedLock()->get(['pc.product_id'])->isNotEmpty();
            $versions = DB::table('skill_versions')->where('product_id', $id)->lockForUpdate()->get(['id', 'status']);
            $isSkill = $category || $versions->isNotEmpty();
            if (! $isSkill) {
                if ($declaredType === 'skill') {
                    return $this->unavailable(ApiErrorCode::SKILL_UNAVAILABLE, '该商品不是可购买的技能，请返回技能市场重新选择。');
                }

                continue;
            }
            if (app(KillSwitchService::class)->isBlockedFor($id, 'sale', current: true)) {
                return $this->unavailable(ApiErrorCode::SKILL_BLOCKED, '该技能已被平台下架，暂不可购买。');
            }
            if (! $customerId) {
                return $this->unavailable(ApiErrorCode::SKILL_LOGIN_REQUIRED, '购买技能需要先登录。', 401);
            }
            if ($declaredType !== null && $declaredType !== 'skill') {
                return $this->unavailable(ApiErrorCode::SKILL_ORDER_TYPE_MISMATCH, '技能商品请通过技能详情页购买。');
            }
            $public = $this->publicRows()->where('product_id', $id)->lockForUpdate()->get(['product_id'])->isNotEmpty();
            if (! $category || ! $public || ! $versions->contains('status', SkillVersion::STATUS_PUBLISHED)) {
                return $this->unavailable(ApiErrorCode::SKILL_UNAVAILABLE, '该技能暂未上架或没有可购买的发布版本，请返回技能市场重新选择。');
            }
        }

        return null;
    }

    protected function unavailable(string $code, string $message, int $status = 409): array
    {
        return compact('code', 'message', 'status');
    }
}
