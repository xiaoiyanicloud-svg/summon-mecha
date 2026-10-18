<?php

namespace Webkul\Core\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as BaseHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends BaseHandler
{
    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // An explicit middleware response already owns its status, headers and
        // body on every surface. Preserve it before any generic renderer runs.
        $this->renderable(fn (HttpResponseException $exception) => $exception->getResponse());

        // X2 · 统一 JSON 错误信封。放在**最前**:本方法在 provider boot 阶段执行,
        // 而 `bootstrap/app.php` 的 withExceptions 回调是之后追加的 —— 数组靠后 = 优先级更低。
        // 实测:APP_DEBUG=false 时,下面的 Bagisto 原生回调会遮蔽 withExceptions 的任何注册,
        // 所以统一信封必须在**这一层**、且在本方法开头注册,才能同时覆盖 debug 开/关。
        $this->registerUnifiedJsonEnvelope();

        if (config('app.debug')) {
            return;
        }

        $this->handleAuthenticationException();

        $this->handleHttpException();

        $this->handleValidationException();

        $this->handleServerException();
    }

    /**
     * X2 · 统一 JSON 错误信封(仅作用于 API 消费方)。
     *
     * 只接管"会落到默认渲染"的异常:控制器自己 catch 并返回的结构不受影响。
     * ⚠ 作用域刻意收窄:只对 `api/*` 路径或 `Accept: application/json` 生效,
     *   web/后台表单请求仍走 Laravel/Bagisto 默认渲染(重定向回带 errors),
     *   否则一次后台表单校验失败会被改成裸 JSON —— 那是大面积回归。
     */
    protected function registerUnifiedJsonEnvelope(): void
    {
        $wantsJson = fn (Request $request) => $request->expectsJson() || $request->is('api/*');

        $this->renderable(function (ValidationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'code'    => 'VALIDATION_ERROR',
                'message' => $e->getMessage(),
                'errors'  => $e->errors(),
            ], 422);
        });

        // ModelNotFoundException 会被 prepareException 先转成 NotFoundHttpException,
        // 故这里处理后者(同时也覆盖"未知路由"的 404)。不回显异常消息 → 不泄漏模型类名。
        $this->renderable(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'code'    => 'NOT_FOUND',
                'message' => '请求的资源不存在。',
            ], 404);
        });

        $this->renderable(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            return response()->json([
                'code'    => 'UNAUTHENTICATED',
                'message' => '未认证或登录已过期。',
            ], 401);
        });

        // 兜底:仅接管**非 HTTP** 的未捕获异常;HTTP 异常(405/419…)继续 fall-through,
        // 保持其既有语义(交给下方 Bagisto 原生回调 / Laravel 默认渲染)。
        $this->renderable(function (Throwable $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            if ($e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            // 生产(APP_DEBUG=false)隐藏细节,不回显异常消息/堆栈
            $message = config('app.debug')
                ? $e->getMessage()
                : '服务器内部错误,请稍后重试。';

            return response()->json([
                'code'    => 'SERVER_ERROR',
                'message' => $message,
            ], 500);
        });
    }

    /**
     * Handle the authentication exception.
     */
    protected function handleAuthenticationException(): void
    {
        $this->renderable(function (AuthenticationException $exception, Request $request) {
            $namespace = $request->is(config('app.admin_url').'/*') ? 'admin' : 'shop';

            if ($request->wantsJson()) {
                return response()->json(['error' => trans("{$namespace}::app.errors.401.description")], 401);
            }

            if ($namespace !== 'admin') {
                // 前台已停用：原 redirect()->guest(route('shop.customer.session.index'))
                return redirect()->guest('/admin');
            }

            return redirect()->guest(route('admin.session.create'));
        });
    }

    /**
     * Handle the http exceptions.
     */
    protected function handleHttpException(): void
    {
        $this->renderable(function (HttpExceptionInterface $exception, Request $request) {
            $namespace = $request->is(config('app.admin_url').'/*') ? 'admin' : 'shop';

            $errorCode = $exception->getStatusCode();

            // Bagisto has custom pages for these four statuses. Other HTTP
            // errors keep Laravel's standard rendering and protocol headers
            // (for example 419, Retry-After on 429, and Allow on 405).
            if (! in_array($errorCode, [401, 403, 404, 503], true)) {
                return $this->renderExceptionResponse($request, $exception);
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'error'       => trans("{$namespace}::app.errors.{$errorCode}.title"),
                    'description' => trans("{$namespace}::app.errors.{$errorCode}.description"),
                ], $errorCode);
            }

            $viewPath = "{$namespace}::errors.{$errorCode}";

            if (! view()->exists($viewPath)) {
                $viewPath = "{$namespace}::errors.index";
            }

            return response()->view($viewPath, compact('errorCode'), $errorCode);
        });
    }

    /**
     * Handle validation exceptions.
     */
    protected function handleValidationException(): void
    {
        $this->renderable(function (ValidationException $exception, Request $request) {
            return parent::convertValidationExceptionToResponse($exception, $request);
        });
    }

    /**
     * Handle the server exceptions.
     */
    protected function handleServerException(): void
    {
        $this->renderable(function (Throwable $throwable, Request $request) {
            $namespace = $request->is(config('app.admin_url').'/*') ? 'admin' : 'shop';

            $errorCode = 500;

            if ($request->wantsJson()) {
                return response()->json([
                    'error'       => trans("{$namespace}::app.errors.{$errorCode}.title"),
                    'description' => trans("{$namespace}::app.shop.errors.{$errorCode}.description"),
                ], $errorCode);
            }

            $viewPath = "{$namespace}::errors.{$errorCode}";

            if (! view()->exists($viewPath)) {
                $viewPath = "{$namespace}::errors.index";
            }

            return response()->view($viewPath, compact('errorCode'), $errorCode);
        });
    }
}
