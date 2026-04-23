<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\SingleSignOnMiddleware;
use Illuminate\Auth\AuthenticationException;
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
        
        // API 路由添加 CORS 中间件
        $middleware->api(prepend: [
            CorsMiddleware::class,
        ]);
        
        // 禁用认证中间件的重定向，确保 API 返回 JSON 而不是重定向到 login 路由
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => '');
        $middleware->redirectUsersTo(fn (\Illuminate\Http\Request $request) => '');
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // API 路由返回 JSON 格式错误
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
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


        // 自定义未认证异常响应（AuthenticationException）
        // 这里作为后备处理，处理其他认证异常情况
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 检查 Authorization 头
                $authHeader = $request->header('Authorization');

                // 没有提供 Authorization 头
                if (!$authHeader) {
                    return response()->json([
                        'code' => 401,
                        'message' => '请先登录后再操作',
                        'data' => ['error_type' => 'token_not_provided']
                    ], 401);
                }

                // 提供了 Authorization 头但格式不正确
                if (!str_starts_with($authHeader, 'Bearer ')) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'Token格式错误，请以 Bearer 开头',
                        'data' => ['error_type' => 'token_format_invalid']
                    ], 401);
                }

                // 提取 Token
                $token = $request->bearerToken();

                // Token 为空
                if (empty($token)) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'Token为空，请重新登录',
                        'data' => ['error_type' => 'token_empty']
                    ], 401);
                }

                // 检查 Token 格式（JWT 应该是三段式：header.payload.signature）
                $parts = explode('.', $token);
                if (count($parts) !== 3) {
                    return response()->json([
                        'code' => 401,
                        'message' => 'Token格式错误，JWT必须是三段式结构',
                        'data' => ['error_type' => 'token_structure_invalid']
                    ], 401);
                }

                // 尝试解码 payload 来检查是否过期
                try {
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                    if ($payload && isset($payload['exp']) && $payload['exp'] < time()) {
                        return response()->json([
                            'code' => 401,
                            'message' => '登录已过期，请重新登录',
                            'data' => ['error_type' => 'token_expired']
                        ], 401);
                    }
                } catch (\Exception $decodeError) {
                    // 解码失败，继续返回通用错误
                }

                // 默认：Token 无效或已过期
                return response()->json([
                    'code' => 401,
                    'message' => '登录已过期或Token无效，请重新登录',
                    'data' => ['error_type' => 'token_invalid_or_expired']
                ], 401);
            }
        });

        // 自定义权限不足异常响应（403）
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 403,
                    'message' => '无权查看，仅管理员可访问',
                    'data' => ['error_type' => 'forbidden']
                ], 403);
            }
        });

        // 自定义验证异常响应（ValidationException）
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $errors = $e->validator->errors();
                $firstError = $errors->first();

                return response()->json([
                    'code' => 422,
                    'message' => $firstError,
                    'data' => [
                        'errors' => $errors->toArray()
                    ]
                ], 422);
            }
        });

        // 处理路由未找到异常（特别是 login 路由未定义的情况）
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 如果是 login 路由未定义，返回 401
                if (str_contains($e->getMessage(), 'login')) {
                    return response()->json([
                        'code' => 401,
                        'message' => '请先登录后再操作',
                        'data' => ['error_type' => 'token_not_provided']
                    ], 401);
                }

                return response()->json([
                    'code' => 500,
                    'message' => '路由错误：' . $e->getMessage(),
                    'data' => []
                ], 500);
            }
        });
    })->create();
