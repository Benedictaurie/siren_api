<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\ReservationController;

// Public Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/buildings', [BuildingController::class, 'index']);
Route::get('/buildings/{slug}', [BuildingController::class, 'show']);

// Protected Routes (Butuh Token Bearer dari Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Customer Routes
    Route::post('/reservations', [ReservationController::class, 'store']);
    
    // Admin Routes (Bisa ditambahkan middleware 'is_admin' nanti)
    Route::prefix('admin')->group(function () {
        Route::put('/reservations/{id}/approve', [ReservationController::class, 'approve']);
        Route::put('/payments/{id}/confirm', [ReservationController::class, 'confirmPayment']);
    });

    // Route untuk redirect ke Google (biasanya dipanggil dari React)
    Route::get('/auth/google/redirect', [AuthController::class, 'getGoogleRedirectUrl']);

    // Route Callback yang Anda daftarkan di Google Console tadi
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
});