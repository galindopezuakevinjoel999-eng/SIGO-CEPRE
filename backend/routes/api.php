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
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamResultController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\CarnetController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\Admin\Content\NewsController as AdminNewsController;
use App\Http\Controllers\Admin\Content\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\Content\FaqController as AdminFaqController;
use App\Http\Controllers\Admin\Content\HomeBlockController as AdminHomeBlockController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\UserController;

Route::prefix('auth')->group(function () {
    Route::get('/google', [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
    // Límite de intentos por minuto contra fuerza bruta.
    Route::post('/google/exchange', [GoogleAuthController::class, 'exchange'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});

// Sitio público (sin sesión). Contenido en MongoDB: solo lo publicado y vigente.
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/home', [PublicController::class, 'home']);
    Route::get('/news', [PublicController::class, 'news']);
    Route::get('/news/{slug}', [PublicController::class, 'newsShow']);
    Route::get('/announcements', [PublicController::class, 'announcements']);
    Route::get('/faqs', [PublicController::class, 'faqs']);
});

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

    // Descarga de archivos privados (storage/app/private). Cada acción aplica su policy.
    // Para subir archivos al editar, el cliente envía POST con _method=PUT (multipart).
    Route::get('/enrollments/{enrollment}/documents/{document}', [EnrollmentController::class, 'downloadDocument'])->name('enrollments.documents.download');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'downloadReceipt'])->name('payments.receipt');
    Route::get('/materials/{material}/download', [MaterialController::class, 'download'])->name('materials.download');
    Route::apiResource('schedules', ScheduleController::class);
    Route::get('/semesters/current', [SemesterController::class, 'current']);
    Route::apiResource('semesters', SemesterController::class);
    Route::apiResource('materials', MaterialController::class);
    // Exámenes y resultados (RF30–RF34). Permisos en ExamPolicy.
    Route::get('/exams/import-template', [ExamController::class, 'template'])->name('exams.template');
    Route::get('/me/exam-results', [ExamResultController::class, 'mine'])->name('exams.mine');
    Route::apiResource('exams', ExamController::class);
    Route::post('/exams/{exam}/import', [ExamController::class, 'import'])->name('exams.import');
    Route::get('/exams/{exam}/results', [ExamController::class, 'results'])->name('exams.results');
    Route::put('/exams/{exam}/publish', [ExamController::class, 'publish'])->name('exams.publish');
    Route::apiResource('attendance', AttendanceController::class);
    // Panel de contenido público (MongoDB). Permisos: ContentPolicy (solo admin).
    // Con archivos (portada, adjuntos), editar se envía como POST con _method=PUT.
    Route::prefix('admin/content')->name('admin.content.')->group(function () {
        Route::apiResource('news', AdminNewsController::class)->parameters(['news' => 'id']);
        Route::apiResource('announcements', AdminAnnouncementController::class)->parameters(['announcements' => 'id']);
        Route::apiResource('faqs', AdminFaqController::class)->parameters(['faqs' => 'id']);
        Route::apiResource('home-blocks', AdminHomeBlockController::class)->parameters(['home-blocks' => 'id']);
    });
    Route::apiResource('reports', ReportController::class);
    Route::apiResource('users', UserController::class);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/enrollments/{enrollment}/carnet/preview', [CarnetController::class, 'preview']);
    Route::get('/enrollments/{enrollment}/carnet/download', [CarnetController::class, 'download']);
});
