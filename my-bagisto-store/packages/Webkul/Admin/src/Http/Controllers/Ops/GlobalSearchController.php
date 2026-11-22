<?php

namespace Webkul\Admin\Http\Controllers\Ops;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillVersion;

/**
 * 顶部 Mega Search 业务扩展检索接口（技能 / 开发者 / 合作申请）。
 *
 * 所有路由均在 config/admin_route_permissions.php 下实行 fail-closed 权限保护。
 */
class GlobalSearchController extends Controller
{
    public function skills(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $like = '%'.$this->escapeLike($query).'%';
        $skillCategoryId = DB::table('category_translations')
            ->where('slug', 'skill')
            ->value('category_id');

        $baseQuery = DB::table('products')
            ->when($skillCategoryId, function ($q) use ($skillCategoryId) {
                $q->whereExists(function ($sub) use ($skillCategoryId) {
                    $sub->select(DB::raw(1))
                        ->from('product_categories')
                        ->whereColumn('product_categories.product_id', 'products.id')
                        ->where('product_categories.category_id', $skillCategoryId);
                });
            })
            ->where(function ($q) use ($like) {
                $q->where('products.sku', 'like', $like)
                    ->orWhereExists(function ($sub) use ($like) {
                        $sub->select(DB::raw(1))
                            ->from('product_flat')
                            ->whereColumn('product_flat.product_id', 'products.id')
                            ->where('product_flat.name', 'like', $like);
                    });
            });

        $total = (clone $baseQuery)->count();

        $rows = (clone $baseQuery)
            ->select('products.id', 'products.sku', 'products.type')
            ->orderByDesc('products.id')
            ->limit(10)
            ->get();

        $productIds = $rows->pluck('id')->all();

        $namesByProduct = $productIds
            ? DB::table('product_flat')
                ->whereIn('product_id', $productIds)
                ->whereNotNull('name')
                ->pluck('name', 'product_id')
            : collect();

        $latestVersions = $productIds
            ? SkillVersion::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('id')
                ->get()
                ->unique('product_id')
                ->keyBy('product_id')
            : collect();

        $data = $rows->map(function ($row) use ($namesByProduct, $latestVersions) {
            $ver = $latestVersions->get($row->id);

            return [
                'id'             => $row->id,
                'sku'            => $row->sku,
                'name'           => $namesByProduct->get($row->id) ?: $row->sku,
                'latest_version' => $ver?->version,
                'version_status' => $ver?->status,
                'url'            => route('admin.catalog.skills.edit', $row->id),
            ];
        })->values();

        return response()->json([
            'data'  => $data,
            'total' => $total,
        ]);
    }

    public function developers(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $like = '%'.$this->escapeLike($query).'%';

        $baseQuery = DB::table('developer_profile')
            ->leftJoin('customers', 'customers.id', '=', 'developer_profile.customer_id')
            ->leftJoin('sellers', 'sellers.id', '=', 'developer_profile.seller_id')
            ->where(function ($q) use ($like) {
                $q->where('developer_profile.company_name', 'like', $like)
                    ->orWhere('developer_profile.operator_name', 'like', $like)
                    ->orWhere('customers.email', 'like', $like)
                    ->orWhere('sellers.name', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(customers.first_name, ''), ' ', COALESCE(customers.last_name, '')) LIKE ?", [$like]);
            });

        $total = (clone $baseQuery)->count();

        $rows = (clone $baseQuery)
            ->select(
                'developer_profile.id',
                'developer_profile.dev_type',
                'developer_profile.realname_status',
                'developer_profile.company_name',
                'developer_profile.operator_name',
                'customers.first_name',
                'customers.last_name',
                'customers.email',
                'sellers.name as seller_name'
            )
            ->orderByDesc('developer_profile.id')
            ->limit(10)
            ->get();

        $statusLabels = [
            DeveloperProfile::REALNAME_PENDING  => '待审核',
            DeveloperProfile::REALNAME_APPROVED => '已实名',
            DeveloperProfile::REALNAME_REJECTED => '已驳回',
        ];

        $data = $rows->map(function ($row) use ($statusLabels) {
            $contactName = trim(($row->last_name ?? '').($row->first_name ?? ''))
                ?: ($row->operator_name ?: '开发者');
            $displayName = $row->company_name ?: ($row->seller_name ?: $contactName);

            return [
                'id'              => $row->id,
                'name'            => $displayName,
                'email'           => $row->email ?: '—',
                'dev_type_label'  => $row->dev_type === DeveloperProfile::DEV_TYPE_COMPANY ? '企业' : '个人',
                'realname_status' => $row->realname_status,
                'realname_label'  => $statusLabels[$row->realname_status] ?? (string) $row->realname_status,
                'url'             => route('admin.customers.developers.show', $row->id),
            ];
        })->values();

        return response()->json([
            'data'  => $data,
            'total' => $total,
        ]);
    }

    public function cooperations(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        if ($query === '') {
            return response()->json(['data' => [], 'total' => 0]);
        }

        $like = '%'.$this->escapeLike($query).'%';

        $baseQuery = CooperationApplication::query()
            ->where(function ($q) use ($like) {
                $q->where('application_no', 'like', $like)
                    ->orWhere('org_name', 'like', $like)
                    ->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_phone', 'like', $like)
                    ->orWhere('followup_person', 'like', $like);
            });

        $total = (clone $baseQuery)->count();

        $rows = (clone $baseQuery)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $data = $rows->map(function (CooperationApplication $app) {
            return [
                'id'              => $app->id,
                'application_no'  => $app->application_no,
                'org_name'        => $app->org_name,
                'contact_name'    => $app->contact_name,
                'type_label'      => CooperationApplication::typeLabel($app->coop_type),
                'status_label'    => CooperationApplication::statusLabel($app->status),
                'followup_person' => $app->followup_person,
                'url'             => route('admin.customers.cooperations.show', $app->id),
            ];
        })->values();

        return response()->json([
            'data'  => $data,
            'total' => $total,
        ]);
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
