<?php

use App\Http\Controllers\FmyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZztController;
use App\Http\Controllers\CgjController;


//注意：api.php 自动带有 /api 前缀，不需要再加 prefix('api')
//注意：加middleware('auth:api')即需要JWT登录
//注意：加middleware('admin')即需要管理员权限
//注意：加middleware('sso')即需要单点认证

// -------------------------- 认证相关接口 --------------------------
//首次设置管理员（仅当没有管理员时可用，限制每小时只能调用3次）
Route::post('/auth/setup-admin', [ZztController::class, 'setupFirstAdmin'])
    ->middleware('throttle:3,60');
//注册
Route::post('/auth/register', [ZztController::class, 'register']);
//发送邮箱验证码
Route::post('/auth/sendCode', [ZztController::class, 'sendEmailCode']);
//登录
Route::post('/auth/login', [ZztController::class, 'login']);
//忘记密码 - 发送重置链接
Route::post('/forgot-password', [FmyController::class, 'forgotPassword']);
//重置密码
Route::post('/reset-password', [FmyController::class, 'resetPassword']);



Route::middleware(['auth:api','sso'])->group(function () {
    //退出登录
    Route::post('/auth/logout', [ZztController::class, 'logout']);
    //获取当前用户信息
    Route::get('/auth/me', [ZztController::class, 'me']);
    //修改个人资料
    Route::put('/auth/profile', [ZztController::class, 'updateProfile']);
    //记住用户（延长Token有效期）
    Route::post('/auth/remember-me', [FmyController::class, 'rememberMe']);
    //取消记住用户（恢复默认Token有效期）
    Route::post('/auth/forget-me', [FmyController::class, 'forgetMe']);
});



// -------------------------- 邀请码管理接口（仅管理员） --------------------------
Route::middleware(['auth:api', 'admin','sso'])->group(function () {
    //生成邀请码
    Route::post('/admin/invite-codes', [ZztController::class, 'generateInviteCode']);
    //获取邀请码列表
    Route::get('/admin/invite-codes', [ZztController::class, 'listInviteCodes']);
    //删除邀请码（支持批量删除，Body传参）
    // 示例: DELETE /admin/invite-codes Body: {"ids": [2, 5, 8]}
    Route::delete('/admin/invite-codes', [ZztController::class, 'deleteInviteCode']);
});


// -------------------------- 设备相关接口 --------------------------
// 需要登录的路由
Route::group(['middleware' => ['auth:api', 'sso']], function () {
//获取设备列表
    Route::get('/devices', [ZztController::class, 'getDeviceList']);
//获取可借设备列表
    Route::get('/devices/available', [ZztController::class, 'getAvailableDeviceList']);
//设备使用统计
    Route::get('/devices-stats', [CgjController::class, 'deviceStats'])->middleware('admin');
//获取设备详情
    Route::get('/devices/{id}', [ZztController::class, 'getDeviceDetail']);
//新增设备
    Route::post('/devices', [ZztController::class, 'addDevice'])->middleware('admin');
});



// 需要登录的路由
Route::group(['middleware' => ['auth:api', 'sso']], function () {
    // 1. 设备模块（管理员权限）
    //修改设备信息（包含状态修改）
    Route::put('/devices/{id}',[CgjController::class,'updateDevice'])->middleware('admin');
    //删除设备
    Route::delete('/devices/{id}', [CgjController::class, 'deleteDevice'])->middleware('admin');

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
    //获取分类下的设备（支持通过name参数按分类名查询）
    Route::get('/category-devices', [CgjController::class, 'getCategoryDevices']);
});






//------用户端借用模块（需要登录）------
Route::middleware(['auth:api', 'sso'])->group(function () {
    //提交借用申请
    Route::post('/createBooking', [FmyController::class,'createBooking']);

    // 取消当前用户指定待审核申请
    Route::delete('/cancel/{id}', [FmyController::class, 'cancelMyPending']);

    // 修改当前登录用户的指定待审核申请信息
    Route::put('/change/{id}', [FmyController::class, 'changeBooking']);

    // 获取当前登录用户的所有借用申请记录
    Route::get('/myBooking', [FmyController::class, 'myBooking']);

    // 获取当前登录用户的单条借用申请详情
    Route::get('/myBooking/{id}', [FmyController::class, 'singleBooking']);

    // 当前登录用户归还指定设备
    Route::post('/return/{id}', [FmyController::class, 'returnBooking']);

    // 删除已结束记录（仅 rejected/returned）
    Route::delete('/delete/{id}', [FmyController::class, 'deleteFinished']);
});


//------- 管理员端(需要登录)-------
Route::middleware(['auth:api', 'admin','sso'])->group(function () {
    // 获取全部借用记录（支持筛选、搜索、分页）
    Route::get('/bookings', [FmyController::class, 'allBooking']);

    // 审批通过（仅 pending，库存-1）
    Route::post('/approve', [FmyController::class, 'approve']);

    // 审批拒绝（仅 pending，需填写原因）
    Route::post('/reject', [FmyController::class, 'reject']);
});





