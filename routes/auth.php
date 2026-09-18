<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Гостевые GET-маршруты для рендеринга Vue/Inertia auth-страниц.
// POST-маршруты (login.store, register.store, password.email, password.update, password.confirm.store, logout, user-password.update)
// уже зарегистрированы Fortify (см. config/fortify.php + vendor/laravel/fortify/routes/routes.php) и НЕ дублируются здесь.

Route::middleware('guest:web')->group(function (): void {
    Route::get('/login', fn () => Inertia::render('Auth/Login'))
        ->name('login');

    Route::get('/register', fn () => Inertia::render('Auth/Register'))
        ->name('register');

    Route::get('/forgot-password', fn () => Inertia::render('Auth/ForgotPassword'))
        ->name('password.request');

    Route::get('/reset-password/{token}', fn (string $token) => Inertia::render('Auth/ResetPassword', [
        'token' => $token,
        'email' => request()->query('email', ''),
    ]))->name('password.reset');
});

// Авторизованный GET для confirm-password (от Fortify, см. routes.php:118-121).
Route::middleware('auth:web')->get('/user/confirm-password', fn () => Inertia::render('Auth/ConfirmPassword'))
    ->name('password.confirm');

// Авторизованный GET /dashboard: личный кабинет с каталогом опубликованных
// курсов (см. DashboardController) — тонкий контроллер вместо замыкания,
// потому что странице нужны данные из БД.
Route::middleware('auth:web')->get('/dashboard', DashboardController::class)->name('dashboard');
