<?php

use app\Http\Controllers\FmyController;
use App\Http\Controllers\CgjController;
use Illuminate\Support\Facades\Route;

/**
 * 借用模块
 *
 */
//提交借用申请
Route::post('/StoreBooking', [FmyController::class,'StoreBooking']);


// 公开路由（不需要登录）
Route::post('/auth/login', [CgjController::class, 'login']);
Route::post('/auth/register', [CgjController::class, 'register']);

// 需要登录的路由
Route::group(['middleware' => 'auth:api'], function () {

    // 1. 登录/登出模块
    Route::post('/auth/logout', [CgjController::class, 'logout']);

    // 2. 设备模块 - 最后4个接口（管理员权限）
    Route::put('/devices/{id}/status', [CgjController::class, 'updateDeviceStatus'])->middleware('admin');
    Route::delete('/devices/{id}', [CgjController::class, 'deleteDevice'])->middleware('admin');
    Route::get('/devices/stats', [CgjController::class, 'deviceStats'])->middleware('admin');

    // 3. 分类模块 - 所有接口
    Route::get('/categories', [CgjController::class, 'getCategories']);
    Route::get('/categories/{id}', [CgjController::class, 'getCategory']);
    Route::post('/categories', [CgjController::class, 'createCategory'])->middleware('admin');
    Route::put('/categories/{id}', [CgjController::class, 'updateCategory'])->middleware('admin');
    Route::delete('/categories/{id}', [CgjController::class, 'deleteCategory'])->middleware('admin');
    Route::get('/categories/{id}/devices', [CgjController::class, 'getCategoryDevices']);

    // 4. 借用模块 - 第1个接口
    Route::post('/bookings', [CgjController::class, 'createBooking']);
});
