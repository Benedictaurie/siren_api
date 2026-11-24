<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\ReservationController;

// ==============================
// PUBLIC ROUTES (Bisa diakses tanpa login)
// ==============================
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Akses Gedung untuk Public / Customer
Route::get('/buildings', [BuildingController::class, 'index']);
Route::get('/buildings/slug/{slug}', [BuildingController::class, 'showBySlug']); 
// Catatan: Gunakan /slug/ agar tidak bentrok dengan ID angka jika pakai show($id)

// ==============================
// PROTECTED ROUTES (Wajib Login / Ada Token)
// ==============================
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Customer Routes
    Route::post('/reservations', [ReservationController::class, 'store']);
    
    // Google Auth (Biasanya di luar middleware, tapi jika butuh token biarkan di sini)
    Route::get('/auth/google/redirect', [AuthController::class, 'getGoogleRedirectUrl']);
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

    // ==============================
    // ADMIN ROUTES (Bisa tambahkan middleware role:admin nanti)
    // ==============================
    Route::prefix('admin')->group(function () {
        // --- CRUD BUILDINGS ---
        Route::get('/buildings', [BuildingController::class, 'adminIndex']);
        Route::post('/buildings', [BuildingController::class, 'store']);
        Route::get('/buildings/{id}', [BuildingController::class, 'show']);
        Route::put('/buildings/{id}', [BuildingController::class, 'update']); // Laravel bisa handle PUT via POST _method
        Route::delete('/buildings/{id}', [BuildingController::class, 'delete']);

        // --- RESERVATIONS ---
        Route::put('/reservations/{id}/approve', [ReservationController::class, 'approve']);
        Route::put('/payments/{id}/confirm', [ReservationController::class, 'confirmPayment']);
    });
});