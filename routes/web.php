<?php

use App\Http\Controllers\Admin\SourceController as AdminSourceController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\SimController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('listings.index')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:5,1');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');
    Route::get('/sims/{number}', [SimController::class, 'show'])->name('sims.show');
    Route::get('/pricing', [PricingController::class, 'show'])->name('pricing.show');
    Route::get('/deals', [DealController::class, 'index'])->name('deals.index');
    Route::post('/deals', [DealController::class, 'store'])->name('deals.store');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/sources', [AdminSourceController::class, 'index'])->name('sources.index');
        Route::post('/sources/{source}/refresh', [AdminSourceController::class, 'scheduleRefresh'])->name('sources.refresh');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::post('/users/{user}/toggle', [AdminUserController::class, 'toggle'])->name('users.toggle');
    });
});
