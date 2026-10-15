<?php

namespace Webkul\Admin\DataGrids\Catalog;

use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shop\Models\SkillReview;

/**
 * 技能评价列表（加固计划 §四 A1 的评价处置台）。
 *
 * ⚠ 与 `Customers\ReviewDataGrid`（Bagisto 自带的商品评价）不是一张表：
 *   那张是 `product_reviews`，这张是 `skill_review`（技能市场评价）。
 *   两者都在，别把它们当成重复实现删掉其中一个。
 *
 * ⚠ 默认视图**列出全部三态**（不是只列待处理的）：与内容安全表不同 ——
 *   评价的"不可见"本身就是需要能被复核的结果（词表会误判），
 *   只列 pending 会让"被机器拦下的那些"永远没人回头看。
 */
class SkillReviewModerationDataGrid extends DataGrid
{
    protected $primaryColumn = 'id';

    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('skill_review')
            ->leftJoin('customers', 'skill_review.customer_id', '=', 'customers.id')
            ->leftJoin('product_flat', function ($join) {
                $join->on('product_flat.product_id', '=', 'skill_review.skill_id')
                    ->where('product_flat.channel', '=', core()->getCurrentChannelCode())
                    ->where('product_flat.locale', '=', app()->getLocale());
            })
            ->select(
                'skill_review.id',
                'skill_review.skill_id',
                'skill_review.rating',
                'skill_review.title',
                'skill_review.content',
                'skill_review.is_anonymous',
                'skill_review.status',
                'skill_review.created_at',
                'customers.first_name as author_first_name',
                'customers.last_name as author_last_name',
                'product_flat.name as product_name',
            )
            ->orderBy('skill_review.id', 'desc');

        $this->addFilter('id', 'skill_review.id');
        $this->addFilter('status', 'skill_review.status');
        $this->addFilter('rating', 'skill_review.rating');

        return $queryBuilder;
    }

    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'id',
            'label'      => 'ID',
            'type'       => 'integer',
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'created_at',
            'label'      => '评价时间',
            'type'       => 'datetime',
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'   => 'skill_id',
            'label'   => '技能',
            'type'    => 'string',
            'closure' => fn ($row) => e($row->product_name ?: '技能 #'.$row->skill_id),
        ]);

        $this->addColumn([
            'index'   => 'author_first_name',
            'label'   => '评价人',
            'type'    => 'string',
            'closure' => function ($row) {
                if ($row->is_anonymous) {
                    // 匿名是用户的选择，后台**照实显示"匿名"**，不顺手把真名透出来 ——
                    // 否则这个选项就是骗人的
                    return '<span class="text-gray-500 text-xs">匿名</span>';
                }

                $name = trim((string) $row->author_first_name.' '.(string) $row->author_last_name);

                return $name !== '' ? e($name) : '<span class="text-gray-400 text-xs">未知</span>';
            },
        ]);

        $this->addColumn([
            'index'              => 'rating',
            'label'              => '评分',
            'type'               => 'string',
            'filterable'         => true,
            'filterable_options' => array_map(
                fn ($v) => ['label' => $v.' 分', 'value' => (string) $v],
                range(SkillReview::RATING_MIN, SkillReview::RATING_MAX)
            ),
            'sortable'           => true,
            'closure'            => fn ($row) => $row->rating
                ? e($row->rating.' 分')
                : '<span class="text-gray-400 text-xs">—</span>',
        ]);

        $this->addColumn([
            'index'   => 'title',
            'label'   => '标题',
            'type'    => 'string',
            'closure' => fn ($row) => trim((string) $row->title) !== ''
                ? e(mb_strimwidth((string) $row->title, 0, 30, '…'))
                : '<span class="text-gray-400 text-xs">未填写</span>',
        ]);

        $this->addColumn([
            'index'   => 'content',
            'label'   => '正文',
            'type'    => 'string',
            'closure' => fn ($row) => trim((string) $row->content) !== ''
                ? e(mb_strimwidth((string) $row->content, 0, 50, '…'))
                : '<span class="text-gray-400 text-xs">未填写</span>',
        ]);

        $this->addColumn([
            'index'              => 'status',
            'label'              => '状态',
            'type'               => 'string',
            'filterable'         => true,
            'filterable_options' => collect(SkillReview::STATUS_LABELS)
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])
                ->values()
                ->all(),
            'sortable'           => true,
            'closure'            => function ($row) {
                $map = [
                    SkillReview::STATUS_VISIBLE => ['可见', 'badge-success'],
                    SkillReview::STATUS_PENDING => ['待人工看', 'badge-pending'],
                    SkillReview::STATUS_HIDDEN  => ['不可见', 'badge-danger'],
                ];

                [$label, $tone] = $map[$row->status] ?? [$row->status, ''];

                return '<span class="badge badge-md '.$tone.'">'.e($label).'</span>';
            },
        ]);
    }

    public function prepareActions()
    {
        $this->addAction([
            'icon'   => 'icon-view',
            'title'  => '查看 / 处置',
            'method' => 'GET',
            'url'    => fn ($row) => route('admin.catalog.skill-review-moderation.show', $row->id),
        ]);
    }
}
