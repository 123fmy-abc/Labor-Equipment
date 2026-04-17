<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SingleSignOnMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 获取当前认证用户
        $user = Auth::guard('api')->user();
        
        // 如果用户未登录，直接通过（让auth:api处理）
        if (!$user) {
            return $next($request);
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

        return $next($request);
    }
}