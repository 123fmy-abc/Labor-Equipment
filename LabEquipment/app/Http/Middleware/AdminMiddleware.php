<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('api');
        
        // 先检查是否已认证，避免触发异常
        if (!$guard->check()) {
            return response()->json([
                'code' => 401,
                'message' => '请先登录后再操作',
                'data' => ['error_type' => 'unauthenticated']
            ], 401);
        }
        
        // 获取当前认证用户
        $user = $guard->user();

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
