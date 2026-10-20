<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Services\Ecosystem\FavoriteException;
use Webkul\Shop\Services\Ecosystem\FavoriteService;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicCursorException;

/**
 * G4-02 · 我的收藏（活动 / 资讯）。
 *
 * 所有入口必须登录；收藏是私有状态，响应一律 `Cache-Control: private, no-store`，
 * 不进入 SSR/OG/sitemap，也不跨账号复用本地状态。
 *
 * 目标可见性完全交给 FavoriteService：新增只接受当前公开目标；读取列表时
 * 已撤稿/下架的目标只计入 stale_count，不返回旧标题或旧 URL。
 */
class FavoriteController extends APIController
{
    public function __construct(
        private readonly FavoriteService $favorites,
        private readonly PublicReadGate $gate,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($unavailable = $this->stopRead()) {
            return $unavailable;
        }

        if ($denied = $this->requireCustomer()) {
            return $denied;
        }

        $perPage = (int) $request->query(
            'per_page',
            (int) config('ecosystem_g4.favorites.default_per_page', 20)
        );

        $maxPerPage = max(1, (int) config('ecosystem_g4.favorites.max_per_page', 50));

        $validator = Validator::make($request->query(), [
            'type'     => ['required', 'string', Rule::in(FavoriteService::TYPES)],
            'cursor'   => ['sometimes', 'string', 'max:512'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ]);

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '收藏参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        if (! $this->favorites->enabled()) {
            return $this->privateJson([
                'data' => [],
                'meta' => [
                    'mode'        => 'cursor',
                    'per_page'    => max(1, min($maxPerPage, $perPage)),
                    'has_more'    => false,
                    'next_cursor' => null,
                    'total'       => null,
                    'stale_count' => 0,
                    'enabled'     => false,
                ],
            ]);
        }

        try {
            $result = $this->favorites->listFor(
                (int) auth()->guard('customer')->id(),
                (string) $request->query('type'),
                $request->query('cursor') !== null ? (string) $request->query('cursor') : null,
                $perPage,
            );
        } catch (FavoriteException $exception) {
            return $this->favoriteError($exception);
        } catch (PublicCursorException $exception) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                $exception->getMessage(),
                ['errors' => ['cursor' => [$exception->getMessage()]], 'reason' => $exception->reason()],
            );
        }

        return $this->privateJson([
            'data' => $result['items'],
            'meta' => [
                'mode'        => 'cursor',
                'per_page'    => max(1, min($maxPerPage, $perPage)),
                'has_more'    => $result['has_more'],
                'next_cursor' => $result['next_cursor'],
                'total'       => null,
                'stale_count' => $result['stale_count'],
                'enabled'     => true,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($unavailable = $this->stopRead()) {
            return $unavailable;
        }

        if ($denied = $this->requireCustomer()) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(FavoriteService::TYPES)],
            'key'  => ['required', 'string', 'max:191'],
        ]);

        if ($validator->fails()) {
            return $this->reject(
                ApiErrorCode::VALIDATION_ERROR,
                '收藏参数不合法。',
                ['errors' => $validator->errors()->toArray()],
            );
        }

        try {
            $result = $this->favorites->add(
                (int) auth()->guard('customer')->id(),
                (string) $validator->validated()['type'],
                (string) $validator->validated()['key'],
            );
        } catch (FavoriteException $exception) {
            return $this->favoriteError($exception);
        }

        return $this->privateJson([
            'data' => $result,
        ], $result['created'] ? '已收藏。' : '已在收藏中。');
    }

    public function destroy(string $type, string $key): JsonResponse
    {
        if ($unavailable = $this->stopRead()) {
            return $unavailable;
        }

        if ($denied = $this->requireCustomer()) {
            return $denied;
        }

        try {
            $result = $this->favorites->remove(
                (int) auth()->guard('customer')->id(),
                $type,
                $key,
            );
        } catch (FavoriteException $exception) {
            return $this->favoriteError($exception);
        }

        return $this->privateJson([
            'data' => $result,
        ], $result['removed'] ? '已取消收藏。' : '原本就不在收藏中。');
    }

    private function stopRead(): ?JsonResponse
    {
        if ($this->gate->available()) {
            return null;
        }

        return $this->fail(
            ApiErrorCode::SERVICE_UNAVAILABLE,
            '公开内容暂时不可用。',
            503,
        );
    }

    private function requireCustomer(): ?JsonResponse
    {
        if (auth()->guard('customer')->user() === null) {
            return $this->fail(ApiErrorCode::UNAUTHENTICATED, '请先登录。', 401);
        }

        return null;
    }

    private function favoriteError(FavoriteException $exception): JsonResponse
    {
        $code = match ($exception->reason()) {
            FavoriteException::TYPE_INVALID    => ApiErrorCode::FAVORITE_TYPE_INVALID,
            FavoriteException::TARGET_NOT_FOUND => ApiErrorCode::FAVORITE_TARGET_NOT_FOUND,
            FavoriteException::LIMIT_REACHED   => ApiErrorCode::FAVORITE_LIMIT_REACHED,
            FavoriteException::DISABLED        => ApiErrorCode::ECOSYSTEM_FAVORITES_DISABLED,
            default                            => ApiErrorCode::SERVER_ERROR,
        };

        return $this->fail($code, $exception->getMessage(), $exception->httpStatus(), [
            'reason' => $exception->reason(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function privateJson(array $payload, string $message = ''): JsonResponse
    {
        if ($message !== '') {
            $payload['message'] = $message;
        }

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
