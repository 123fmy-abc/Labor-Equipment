<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZztController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 所有接口统一用ZztController，按功能分组
// 注意：api.php 自动带有 /api 前缀，不需要再加 prefix('api')

// -------------------------- 认证相关接口 --------------------------
Route::post('/register', [ZztController::class, 'register']); // 1. 注册（含邀请码）
Route::post('/login', [ZztController::class, 'login']); // 2. 登录
Route::post('/logout', [ZztController::class, 'logout']); // 3. 退出登录
Route::get('/me', [ZztController::class, 'me']); // 4. 获取当前用户信息
Route::put('/profile', [ZztController::class, 'updateProfile']); // 5. 修改个人资料
Route::post('/send-code', [ZztController::class, 'sendEmailCode']); // 6. 发送邮箱验证码
Route::post('/verify-code', [ZztController::class, 'verifyEmailCode']); // 7. 校验邮箱验证码

// -------------------------- 设备相关接口 --------------------------
Route::get('/devices', [ZztController::class, 'getDeviceList']); // 8. 获取设备列表
Route::get('/devices/available', [ZztController::class, 'getAvailableDeviceList']); // 9. 获取可借设备列表
Route::get('/devices/{id}', [ZztController::class, 'getDeviceDetail']); // 10. 获取设备详情
Route::post('/devices', [ZztController::class, 'addDevice']); // 11. 新增设备（仅管理员）