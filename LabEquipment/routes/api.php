<?php

use app\Http\Controllers\FmyController;
use Illuminate\Support\Facades\Route;

/**
 * 借用模块
 *
 */
//提交借用申请
Route::post('/StoreBooking', [FmyController::class,'StoreBooking']);
