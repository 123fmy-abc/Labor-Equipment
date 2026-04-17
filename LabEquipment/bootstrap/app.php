<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SingleSignOnMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'sso' => SingleSignOnMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 路由返回 JSON 格式错误
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // 自定义未认证异常响应
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作',
                    'data' => null
                ], 401);
            }
        });

        // 自定义限流异常响应
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;
                return response()->json([
                    'code' => 429,
                    'message' => '请求过于频繁，请稍后再试',
                    'data' => [
                        'retry_after' => $retryAfter,
                    ]
                ], 429);
            }
        });

        // 自定义JWT未授权异常响应
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getMessage();

                if (str_contains($message, 'Token not provided')) {
                    return response()->json([
                        'code' => 401,
                        'message' => '请先登录后再操作',
                        'data' => null
                    ], 401);
                }

                if (str_contains($message, 'blacklisted')) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'token已失效，请重新登录',
                        'data' => null
                    ], 401);
                }

                if (str_contains($message, 'expired')) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'token已过期，请重新登录',
                        'data' => null
                    ], 401);
                }

                if (str_contains($message, 'invalid')) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'token无效，请重新登录',
                        'data' => null
                    ], 401);
                }

                return response()->json([
                    'code' => 401,
                    'message' => 'token错误，请重新登录',
                    'data' => null
                ], 401);
            }
        });
    })->create();
