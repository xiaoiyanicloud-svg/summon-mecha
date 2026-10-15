<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Webkul\MagicAI\Facades\MagicAI;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Repositories\ProductReviewAttachmentRepository;
use Webkul\Product\Repositories\ProductReviewRepository;
use Webkul\Shop\Http\Resources\ProductReviewResource;

class ReviewController extends APIController
{
    /**
     * Create a controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ProductRepository $productRepository,
        protected ProductReviewRepository $productReviewRepository,
        protected ProductReviewAttachmentRepository $productReviewAttachmentRepository
    ) {}

    /**
     * Pending review status.
     */
    const STATUS_PENDING = 'pending';

    /**
     * Approved review status.
     */
    const STATUS_APPROVED = 'approved';

    /**
     * Product listings.
     */
    public function index(int $id): JsonResource
    {
        $product = $this->productRepository
            ->findOrFail($id)
            ->reviews()
            ->where('status', self::STATUS_APPROVED)
            ->paginate(8);

        if (core()->getConfigData('catalog.products.review.censoring_reviewer_name')) {
            $product->getCollection()->transform(function ($review) {
                $review->name = $this->censorReviewerName($review->name);

                return $review;
            });
        }

        return ProductReviewResource::collection($product);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(int $id): JsonResource
    {
        $this->validate(request(), [
            // A9 · 补长度上限:此前 title / comment 是无上限的自由文本,
            //   用户可以塞进整本书 —— 既撑爆存储,也让后台审核台无法阅读。
            //   title 取列宽(string 255),comment 取与技能评价(SkillReview::CONTENT_MAX)×2 的宽裕值。
            'title'         => 'required|string|max:255',
            'comment'       => 'required|string|max:2000',
            'rating'        => 'required|numeric|min:1|max:5',
            // A9 · 附件补数量与大小上限:此前只校验 mimetype,不限制单个文件大小 ——
            //   一个几百 MB 的"视频"就能把请求体和磁盘喂满。20MB 足够放下普通图片与短视频。
            'attachments'   => 'array|max:5',
            'attachments.*' => 'file|mimetypes:image/*,video/*|max:20480',
        ]);

        $data = array_merge(request()->only([
            'title',
            'comment',
            'rating',
        ]), [
            'attachments' => request()->file('attachments') ?? [],
            'status'      => self::STATUS_PENDING,
            'product_id'  => $id,
        ]);

        $data['name'] = auth()->guard('customer')->user()?->name ?? request()->input('name');
        $data['customer_id'] = auth()->guard('customer')->id() ?? null;

        Event::dispatch('customer.review.create.before', $id);

        $review = $this->productReviewRepository->create($data);

        $this->productReviewAttachmentRepository->upload($data['attachments'], $review);

        Event::dispatch('customer.review.create.after', $review);

        return new JsonResource([
            'message' => trans('shop::app.products.view.reviews.success'),
        ]);
    }

    /**
     * Translate the specified resource in storage.
     */
    public function translate(int $productId, int $reviewId): JsonResponse
    {
        $review = $this->productReviewRepository->find($reviewId);

        if ($review?->status !== self::STATUS_APPROVED) {
            return $this->fail(ApiErrorCode::REVIEW_NOT_APPROVED, trans('shop::app.products.view.reviews.empty-review'), 400);
        }

        $currentLocale = core()->getCurrentLocale();

        $prompt = "
        Translate the following product review to $currentLocale->name. Ensure that the translation retains the sentiment and conveys the meaning accurately. If specific product-related terms or expressions are commonly used in the $currentLocale->name, please adapt accordingly.
        ---

        **Original Product Review:**
        $review->comment

        ---
        Translation:
        ";

        try {
            $model = core()->getConfigData('general.magic_ai.review_translation.model');

            $response = MagicAI::setModel($model)
                ->setPrompt($prompt)
                ->ask();

            return new JsonResponse([
                'content' => $response,
            ]);
        } catch (\Exception $e) {
            return $this->fail(ApiErrorCode::REVIEW_TRANSLATE_FAILED, trans('shop::app.errors.500.title'), 500);
        }
    }

    /**
     * Censoring the reviewer name.
     */
    private function censorReviewerName(string $name): string
    {
        return collect(explode(' ', $name))
            ->map(fn ($part) => mb_substr($part, 0, 1).str_repeat('*', max(mb_strlen($part) - 1, 0)))
            ->join(' ');
    }
}
