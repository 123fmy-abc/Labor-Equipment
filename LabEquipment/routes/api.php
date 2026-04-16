<?php

use App\Http\Controllers\FmyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZztController;
use App\Http\Controllers\CgjController;
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
Route::post('/devices', [ZztController::class, 'addDevice']); // 11. 新增设备（仅管理员）










//------用户端借用模块（需要登录）------
Route::middleware('auth:api')->group(function () {
    //提交借用申请
    Route::post('/createBooking', [FmyController::class,'createBooking']);

    // 取消当前用户指定待审核申请
    Route::delete('/{id}/cancel', [FmyController::class, 'cancelMyPending']);

    // 修改当前登录用户的指定待审核申请信息
    Route::put('/{id}/change', [FmyController::class, 'changeBooking']);

    // 获取当前登录用户的所有借用申请记录
    Route::get('/myBooking', [FmyController::class, 'myBooking']);

    // 获取当前登录用户的单条借用申请详情
    Route::get('/{id}/myBooking', [FmyController::class, 'singleBooking']);

    // 当前登录用户归还指定设备
    Route::post('/{id}/return', [FmyController::class, 'returnBooking']);

    // 删除已结束记录（仅 rejected/returned）
    Route::delete('/{id}/delete', [FmyController::class, 'deleteFinished']);
});


//------- 管理员端(需要登录)-------
Route::middleware(['auth:api', 'admin'])->group(function () {
    // 获取全部借用记录（支持筛选、搜索、分页）
    Route::get('/bookings', [FmyController::class, 'allBooking']);

    // 审批通过（仅 pending，库存-1）
    Route::post('/approve', [FmyController::class, 'approve']);

    // 审批拒绝（仅 pending，需填写原因）
    Route::post('/reject', [FmyController::class, 'reject']);
});





// 需要登录的路由
Route::group(['middleware' => 'auth:api'], function () {
    // 1. 设备模块 - 最后4个接口（管理员权限）
    //修改设备信息
    Route::put('/devices/{id}',[CgjController::class,'updateDevice'])->middleware('admin');
    //修改设备状态
    Route::put('/devices/{id}/status', [CgjController::class, 'updateDeviceStatus'])->middleware('admin');
    //删除设备
    Route::delete('/devices/{id}', [CgjController::class, 'deleteDevice'])->middleware('admin');
    //设备使用统计
    Route::get('/devices/stats', [CgjController::class, 'deviceStats'])->middleware('admin');

    // 2. 分类模块 - 所有接口
    //获取全部分类
    Route::get('/categories', [CgjController::class, 'getCategories']);
    //获取单个分类
    Route::get('/categories/{id}', [CgjController::class, 'getCategory']);
    //新增分类
    Route::post('/categories', [CgjController::class, 'createCategory'])->middleware('admin');
    //修改分类
    Route::put('/categories/{id}', [CgjController::class, 'updateCategory'])->middleware('admin');
    //删除分类
    Route::delete('/categories/{id}', [CgjController::class, 'deleteCategory'])->middleware('admin');
    //获取分类下的设备
    Route::get('/categories/{id}/devices', [CgjController::class, 'getCategoryDevices']);
    //3.借用模块-第1个接口
    //提交借用申请
    Route::post('/bookings',[CgjController::class,'createBooking']);
});
