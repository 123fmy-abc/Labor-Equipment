<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 1. 先判断用户是否登录
        if (!Auth::check()) {
            return response()->json([
                'code' => 401,
                'message' => '请先登录',
                'data' => []
            ], 401);
        }

        // 2. 再判断用户是否是管理员
        $user = Auth::user();
        if (!$user->isAdmin()) {
            return response()->json([
                'code' => 403,
                'message' => '无管理员权限',
                'data' => []
            ], 403);
        }

        // 3. 权限验证通过，继续执行接口
        return $next($request);
    }
}
