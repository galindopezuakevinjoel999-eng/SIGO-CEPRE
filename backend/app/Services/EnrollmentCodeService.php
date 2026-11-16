<?php

namespace App\Services;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;

class EnrollmentCodeService
{
    /**
     * Genera un código de matrícula único con el formato:
     * [GRUPO]-[CARRERA]-[AULA]-[SEMESTRE]-[NÚMERO]
     * Ejemplo: A-ING-01-252-001
     */
    public function generate(Enrollment $enrollment): string
    {
        return DB::transaction(function () use ($enrollment) {
            $career = Career::findOrFail($enrollment->career_id);
            $classroom = Classroom::findOrFail($enrollment->classroom_id);
            $semester = $enrollment->semester_id
                ? Semester::findOrFail($enrollment->semester_id)
                : Semester::current();

            if (! $semester) {
                throw new \RuntimeException('No hay semestre activo configurado.');
            }

            $group = $career->group;  // A o B
            $careerCode = strtoupper($career->code);
            $classroomNumber = str_pad($classroom->number, 2, '0', STR_PAD_LEFT);
            $semesterCode = $semester->code;

            // Obtener el siguiente número correlativo (con lock para evitar duplicados)
            $lastEnrollment = Enrollment::where('enrollment_code', 'LIKE', "{$group}-{$careerCode}-{$classroomNumber}-{$semesterCode}-%")
                ->lockForUpdate()
                ->orderByRaw('CAST(SUBSTRING_INDEX(enrollment_code, "-", -1) AS UNSIGNED) DESC')
                ->first();

            $nextNumber = 1;
            if ($lastEnrollment && $lastEnrollment->enrollment_code) {
                $parts = explode('-', $lastEnrollment->enrollment_code);
                $lastNumber = (int) end($parts);
                $nextNumber = $lastNumber + 1;
            }

            $numberPadded = str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

            $code = "{$group}-{$careerCode}-{$classroomNumber}-{$semesterCode}-{$numberPadded}";

            // Guardar en la matrícula
            $enrollment->enrollment_code = $code;
            $enrollment->group = $group;
            if (! $enrollment->semester_id) {
                $enrollment->semester_id = $semester->id;
            }
            $enrollment->save();

            return $code;
        });
    }
}
