<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 注册中间件别名
        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 路由返回 JSON 格式错误
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        // 自定义未认证异常响应
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 检查是否有前一个异常（JWT错误）
                $previous = $e->getPrevious();
                
                if ($previous instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录已过期，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                if ($previous instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录凭证无效，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                if ($previous instanceof \Tymon\JWTAuth\Exceptions\JWTException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录凭证错误，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                // 没有前一个异常，说明是真的未登录（没有Token）
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作',
                    'data' => null
                ], 401);
            }
        });

        // 自定义 JWT 异常响应
        $exceptions->render(function (JWTException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getMessage();
                
                // 根据错误消息判断具体原因
                if (str_contains($message, 'expired')) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录已过期，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                if (str_contains($message, 'invalid') || str_contains($message, 'could not be parsed')) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录凭证无效，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                return response()->json([
                    'code' => 401,
                    'message' => '登录凭证错误，请重新登录',
                    'data' => null
                ], 401);
            }
        });

        // 自定义限流异常响应
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;
                
                return response()->json([
                    'code' => 429,
                    'message' => '请求过于频繁，请稍后再试',
                    'data' => [
                        'retry_after' => $retryAfter,
                        'retry_after_seconds' => $retryAfter,
                    ]
                ], 429);
            }
        });
    })
    ->create();
