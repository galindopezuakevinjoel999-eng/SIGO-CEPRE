<?php

use Illuminate\Support\Facades\Route;

// Ruta principal
Route::get('/', function () {
    return view('welcome');
});

// Ruta "login" que exige el middleware de autenticación cuando una petición
// no-JSON no está autenticada. El login con Google vive en routes/api.php.
Route::get('/login', function () {
    return redirect('/api/auth/google');
})->name('login');
