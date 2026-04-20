<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        // 允许的域名（本地 + NATAPP）
        $allowedOrigins = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:3000',
        ];
        
        $origin = $request->header('Origin');
        
        // 允许所有 natapp.cc 域名
        if ($origin && (in_array($origin, $allowedOrigins) || str_contains($origin, 'natapp.cc'))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        
        // 处理预检请求
        if ($request->getMethod() === 'OPTIONS') {
            $response->setStatusCode(204);
        }
        
        return $response;
    }
}