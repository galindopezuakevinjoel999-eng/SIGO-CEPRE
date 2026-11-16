<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Auth\GoogleAuthController;

// Ruta principal
Route::get('/', function () {
    return view('welcome');
});

// Login con Google
Route::get('/auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Fallback login route required by auth middleware
Route::get('/login', function () {
    return redirect('/auth/google');
})->name('login');