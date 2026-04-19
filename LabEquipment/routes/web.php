<?php

use App\Http\Controllers\FmyController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

