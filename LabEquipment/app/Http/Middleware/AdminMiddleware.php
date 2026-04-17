<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 获取当前认证用户
        $user = Auth::guard('api')->user();


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
