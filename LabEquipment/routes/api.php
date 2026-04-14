<?php

use app\Http\Controllers\FmyController;
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





// 用户端借用申请路由
Route::prefix('api/bookings')->group(function () {
    // 获取当前登录用户的所有借用申请记录
    Route::get('/', [FmyController::class, 'myBooking']);

    // 获取当前登录用户的单条借用申请详情
    Route::get('/{id}', [FmyController::class, 'singleBooking']);

    // 修改当前登录用户的指定待审核申请信息
    Route::put('/{id}', [FmyController::class, 'changeBooking']);

    // 取消当前用户指定待审核申请
    Route::delete('/{id}/cancel', [FmyController::class, 'cancelMyPending']);

    // 当前登录用户归还指定设备
    Route::post('/{id}/return', [FmyController::class, 'returnBooking']);

    // 删除已结束记录（仅 rejected/returned）
    Route::delete('/{id}', [FmyController::class, 'deleteFinished']);
});

// 管理员端路由
Route::prefix('api/admin')->group(function () {
    // 获取全部借用记录（支持筛选、搜索、分页）
    Route::get('/bookings', [FmyController::class, 'allBooking']);

    // 审批通过（仅 pending，库存-1）
    Route::post('/bookings/{id}/approve', [FmyController::class, 'approve']);

    // 审批拒绝（仅 pending，需填写原因）
    Route::post('/bookings/{id}/reject', [FmyController::class, 'reject']);
});
