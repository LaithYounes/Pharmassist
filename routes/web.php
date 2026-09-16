<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardAuthController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/dashboard/login', [DashboardAuthController::class, 'form'])->name('login');
Route::post('/dashboard/login', [DashboardAuthController::class, 'login'])->name('dashboard.login');
Route::post('/dashboard/logout', [DashboardAuthController::class, 'logout'])->middleware('auth:web')->name('dashboard.logout');

Route::middleware(['auth:web', 'can:manage-pharmacy'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/top-manufacturers', [DashboardController::class, 'topManufacturers'])
        ->name('dashboard.topManufacturers');
});
