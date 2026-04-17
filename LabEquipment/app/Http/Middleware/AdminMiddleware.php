<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 获取当前认证用户（已由jwt.auth中间件验证）
        $user = Auth::guard('api')->user();
        
        // 如果用户未登录
        if (!$user) {
            return response()->json([
                'code' => 401,
                'message' => '请先登录后再操作',
                'data' => null
            ], 401);
        }
        
        // 单点登录验证：检查当前Token是否是最新的
        $token = $request->bearerToken();
        $cachedToken = cache()->get('user_token_' . $user->id);
        
        // 如果缓存中有Token且与当前Token不匹配，说明已被挤下线
        if ($cachedToken && $cachedToken !== $token) {
            return response()->json([
                'code' => 401,
                'message' => '您的账号已在其他地方登录，请重新登录',
                'data' => null
            ], 401);
        }

        // 判断用户是否是管理员
        if (!$user->isAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '无管理员权限',
                'data' => []
            ], 403);
        }

        // 权限验证通过，继续执行接口
        return $next($request);
    }
}
