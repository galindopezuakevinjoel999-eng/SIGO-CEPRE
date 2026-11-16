<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('enrollment_code')->nullable()->unique()->after('id');
            $table->foreignId('career_id')->nullable()->after('career')->constrained('careers')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->after('career_id')->constrained('classrooms')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->after('classroom_id')->constrained('semesters')->nullOnDelete();
            $table->char('group', 1)->nullable()->after('semester_id');
            $table->integer('current_step')->default(1)->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropForeign(['career_id']);
            $table->dropForeign(['classroom_id']);
            $table->dropForeign(['semester_id']);
            $table->dropColumn(['enrollment_code', 'career_id', 'classroom_id', 'semester_id', 'group', 'current_step']);
        });
    }
};
