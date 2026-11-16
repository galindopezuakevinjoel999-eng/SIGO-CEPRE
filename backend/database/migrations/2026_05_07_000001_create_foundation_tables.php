<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Carreras (dinámico, configurado por admin) ──
        Schema::create('careers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();         // ING, EDU, ADM, CON, etc.
            $table->string('name');                         // Ingeniería de Sistemas, etc.
            $table->char('group', 1);                       // A (Ingenierías), B (Salud) o C (Ciencias de la empresa)
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // ── Aulas ──
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->string('number', 10);                   // 01, 02, etc.
            $table->string('name')->nullable();              // "Aula 101", etc.
            $table->integer('capacity')->default(30);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        // ── Semestres / Periodos académicos ──
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();            // 252 = 2025 ciclo 2
            $table->integer('year');
            $table->integer('cycle');                         // 1 o 2
            $table->string('name')->nullable();               // "2025-II"
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // ── Configuración del sistema (clave-valor) ──
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');        // string, integer, decimal, boolean, json
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // ── Agregar campos al usuario para sanciones y foto ──
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'photo_url')) {
                $table->string('photo_url')->nullable()->after('status');
            }
            if (! Schema::hasColumn('users', 'absence_count')) {
                $table->integer('absence_count')->default(0)->after('photo_url');
            }
            if (! Schema::hasColumn('users', 'student_status')) {
                $table->enum('student_status', ['activo', 'en_riesgo', 'inhabilitado'])
                      ->default('activo')->after('absence_count');
            }
        });

        // ── Tabla de logs de auditoría (reversiones de bloqueo, etc.) ──
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');                        // revert_block, approve_enrollment, etc.
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // ── Tabla de notificaciones en sistema ──
        Schema::create('notifications_system', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');                          // absence_warning, payment_reminder, etc.
            $table->string('title');
            $table->text('message');
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_system');
        Schema::dropIfExists('audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $columns = ['photo_url', 'absence_count', 'student_status'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('classrooms');
        Schema::dropIfExists('careers');
    }
};
