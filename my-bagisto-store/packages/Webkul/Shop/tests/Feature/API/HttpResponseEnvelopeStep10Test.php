<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

it('Step 10 preserves explicit API middleware responses and retry headers', function (bool $debug) {
    config(['app.debug' => $debug]);
    $intended = response()->json(['code' => 'RATE_LIMITED', 'message' => '请稍后重试。'], 429,
        ['Retry-After' => '60', 'X-RateLimit-Remaining' => '0']);
    $response = app(ExceptionHandler::class)->render(Request::create('/api/step10-response', 'POST'),
        new HttpResponseException($intended));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and(json_decode($response->getContent(), true))->toBe(['code' => 'RATE_LIMITED', 'message' => '请稍后重试。']);
})->with([true, false]);

it('Step 10 preserves an explicit non API web response on both debug settings', function (bool $debug) {
    config(['app.debug' => $debug]);
    $intended = response('<p>Step 10 explicit middleware response.</p>', 429,
        ['Content-Type' => 'text/html', 'Retry-After' => '41', 'X-Step10-Response' => 'keep']);
    $response = app(ExceptionHandler::class)->render(Request::create('/step10-explicit-response', 'POST'),
        new HttpResponseException($intended));

    expect($response)->toBe($intended)
        ->and($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('41')
        ->and($response->headers->get('X-Step10-Response'))->toBe('keep')
        ->and($response->getContent())->toBe('<p>Step 10 explicit middleware response.</p>');
})->with([true, false]);

it('Step 10 retains web throttle status and retry headers through default HTTP rendering', function (bool $debug) {
    config(['app.debug' => $debug]);
    $response = app(ExceptionHandler::class)->render(Request::create('/step10-throttle-response'),
        new ThrottleRequestsException('Too many requests.', null,
            ['Retry-After' => '37', 'X-RateLimit-Remaining' => '0']));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('37')
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0')
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
})->with([true, false]);

it('Step 10 retains the web CSRF 419 response after Laravel maps a token mismatch', function (bool $debug) {
    config(['app.debug' => $debug]);
    $response = app(ExceptionHandler::class)->render(Request::create('/step10-csrf-response', 'POST'),
        new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419)
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
})->with([true, false]);

it('Step 10 retains web method rejection status and the Allow header', function (bool $debug) {
    config(['app.debug' => $debug]);
    $response = app(ExceptionHandler::class)->render(Request::create('/step10-method-response', 'POST'),
        new MethodNotAllowedHttpException(['GET', 'HEAD']));

    expect($response->getStatusCode())->toBe(405)
        ->and($response->headers->get('Allow'))->toBe('GET, HEAD')
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
})->with([true, false]);

it('Step 10 preserves the existing Bagisto web error page for known HTTP statuses', function (int $status, string $namespace) {
    config(['app.debug' => false]);
    $uri = $namespace === 'admin' ? '/'.config('app.admin_url').'/step10-known-response' : '/step10-known-response';
    // Theme resolution reads the container request, just as a real HTTP request
    // does. Keep it identical to the request passed directly to the handler.
    $request = Request::create($uri);
    $this->app->instance('request', $request);
    $response = app(ExceptionHandler::class)->render($request, new HttpException($status));
    $view = $response->getOriginalContent();

    expect($response->getStatusCode())->toBe($status)
        ->and($view->getName())->toBe($namespace.'::errors.index')
        ->and($view->getData()['errorCode'])->toBe($status);
})->with([
    [401, 'shop'], [403, 'shop'], [404, 'shop'], [503, 'shop'],
    [401, 'admin'], [403, 'admin'], [404, 'admin'], [503, 'admin'],
]);

it('Step 10 still hides unexpected API exception detail', function () {
    config(['app.debug' => false]);
    $response = app(ExceptionHandler::class)->render(Request::create('/api/step10-error'),
        new RuntimeException('private-internal-detail'));

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode($response->getContent(), true)['code'])->toBe('SERVER_ERROR')
        ->and($response->getContent())->not->toContain('private-internal-detail');
});

it('Step 10 retains redirect errors and old input for an ordinary non API web form validation failure', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->post('/step10-form-envelope', function (Request $request) {
        $request->validate(['email' => ['required', 'email']]);

        return response('The invalid form must not reach this response.');
    });

    $response = $this->withSession([])->from('/step10-form-envelope')
        ->post('/step10-form-envelope', ['email' => 'invalid-address']);

    $response->assertRedirect('/step10-form-envelope')
        ->assertSessionHasErrors(['email'])
        ->assertSessionHasInput('email', 'invalid-address');
    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Content-Type'))->toContain('text/html');
});
