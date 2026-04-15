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
Route::post('/auth/register', [ZztController::class, 'register']); // 1. 注册
Route::post('/auth/setup-admin', [ZztController::class, 'setupFirstAdmin']); // 0. 首次设置管理员（仅当没有管理员时可用）
Route::post('/auth/login', [ZztController::class, 'login']); // 2. 登录
Route::post('/auth/logout', [ZztController::class, 'logout']); // 3. 退出登录
Route::get('/auth/me', [ZztController::class, 'me']); // 4. 获取当前用户信息
Route::put('/auth/profile', [ZztController::class, 'updateProfile']); // 5. 修改个人资料
Route::post('/auth/sendCode', [ZztController::class, 'sendEmailCode']); // 6. 发送邮箱验证码
Route::post('/auth/verify-code', [ZztController::class, 'verifyEmailCode']); // 7. 校验邮箱验证码

// -------------------------- 邀请码管理接口（仅管理员） --------------------------
Route::post('/admin/invite-codes', [ZztController::class, 'generateInviteCode']); // 生成邀请码
Route::get('/admin/invite-codes', [ZztController::class, 'listInviteCodes']); // 获取邀请码列表
Route::delete('/admin/invite-codes/{id}', [ZztController::class, 'deleteInviteCode']); // 删除邀请码

// -------------------------- 设备相关接口 --------------------------
Route::get('/devices', [ZztController::class, 'getDeviceList']); // 8. 获取设备列表
Route::get('/devices/available', [ZztController::class, 'getAvailableDeviceList']); // 9. 获取可借设备列表
Route::get('/devices/{id}', [ZztController::class, 'getDeviceDetail']); // 10. 获取设备详情
Route::post('/devices', [ZztController::class, 'addDevice']); // 11. 新增设备
