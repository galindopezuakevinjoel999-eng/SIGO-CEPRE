<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Puesto de cada estudiante dentro de su carrera (base del cálculo de ingresantes). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->after('group')->constrained('careers')->nullOnDelete();
            $table->unsignedInteger('rank_in_career')->nullable()->after('rank_in_group');
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('career_id');
            $table->dropColumn('rank_in_career');
        });
    }
};
