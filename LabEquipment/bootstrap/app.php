<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AdminMiddleware;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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

        // 自定义未认证异常响应（JWT 验证失败也会进入这里）
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 检查是否是 JWT 相关的错误
                $previous = $e->getPrevious();
                
                if ($previous instanceof TokenExpiredException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录已过期，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                if ($previous instanceof TokenInvalidException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录凭证无效，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                if ($previous instanceof JWTException) {
                    return response()->json([
                        'code' => 401,
                        'message' => '登录凭证错误，请重新登录',
                        'data' => null
                    ], 401);
                }
                
                // 默认：未登录
                return response()->json([
                    'code' => 401,
                    'message' => '请先登录后再操作',
                    'data' => null
                ], 401);
            }
        });

        // 自定义 JWT Token 过期异常
        $exceptions->render(function (TokenExpiredException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 401,
                    'message' => '登录已过期，请重新登录',
                    'data' => null
                ], 401);
            }
        });

        // 自定义 JWT Token 无效异常
        $exceptions->render(function (TokenInvalidException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 401,
                    'message' => '登录凭证无效，请重新登录',
                    'data' => null
                ], 401);
            }
        });

        // 自定义 JWT 其他异常（Token为空等）
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
    })
    ->create();
