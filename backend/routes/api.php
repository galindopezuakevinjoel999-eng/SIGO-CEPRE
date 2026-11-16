<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CareerController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SemesterController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\CarnetController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;

Route::prefix('auth')->group(function () {
    Route::get('/google', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
    // Límite de intentos por minuto contra fuerza bruta.
    Route::post('/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

Route::get('/news', [NewsController::class, 'publicIndex']);
Route::get('/news/{news}', [NewsController::class, 'publicShow']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('role:admin');

    Route::get('/user', function (Request $request) {
        return response()->json($request->user());
    });

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::apiResource('careers', CareerController::class);
    Route::apiResource('classrooms', ClassroomController::class);
    Route::apiResource('courses', CourseController::class);
    // Matrícula y pagos: crear/editar exige perfil completo (DNI y teléfono).
    // Los permisos por rol están en EnrollmentPolicy y PaymentPolicy.
    Route::middleware('profile.completed')->group(function () {
        Route::post('/enrollments', [EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::match(['put', 'patch'], '/enrollments/{enrollment}', [EnrollmentController::class, 'update'])->name('enrollments.update');
        Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    });
    Route::apiResource('enrollments', EnrollmentController::class)->except(['store', 'update']);
    Route::apiResource('payments', PaymentController::class)->except(['store']);
    Route::put('/enrollments/{enrollment}/status', [EnrollmentController::class, 'updateStatus'])->name('enrollments.status');
    Route::put('/payments/{payment}/validate', [PaymentController::class, 'validatePayment'])->name('payments.validate');
    Route::apiResource('schedules', ScheduleController::class);
    Route::get('/semesters/current', [SemesterController::class, 'current']);
    Route::apiResource('semesters', SemesterController::class);
    Route::apiResource('materials', MaterialController::class);
    Route::apiResource('grades', GradeController::class);
    Route::apiResource('attendance', AttendanceController::class);
    Route::apiResource('news', NewsController::class)->except(['index', 'show']);
    Route::apiResource('announcements', AnnouncementController::class);
    Route::apiResource('reports', ReportController::class);
    Route::apiResource('users', UserController::class);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/enrollments/{enrollment}/carnet/preview', [CarnetController::class, 'preview']);
    Route::get('/enrollments/{enrollment}/carnet/download', [CarnetController::class, 'download']);
});
