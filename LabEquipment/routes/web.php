<?php

use App\Http\Controllers\FmyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 用户端借用申请路由（需要登录）
Route::middleware('auth:api')->prefix('api/bookings')->group(function () {
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

// 管理员端路由（需要登录 + 管理员权限）
Route::middleware(['auth:api', 'admin'])->prefix('api/admin')->group(function () {
    // 获取全部借用记录（支持筛选、搜索、分页）
    Route::get('/bookings', [FmyController::class, 'allBooking']);

    // 审批通过（仅 pending，库存-1）
    Route::post('/bookings/{id}/approve', [FmyController::class, 'approve']);

    // 审批拒绝（仅 pending，需填写原因）
    Route::post('/bookings/{id}/reject', [FmyController::class, 'reject']);
});
