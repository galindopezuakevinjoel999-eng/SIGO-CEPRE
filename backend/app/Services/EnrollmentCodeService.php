<?php

namespace App\Services;

use App\Exceptions\EnrollmentCodeException;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Semester;

/**
 * Código de matrícula: [GRUPO]-[CARRERA]-[AULA]-[SEMESTRE]-[CORRELATIVO]
 * Ejemplo: A-SIS-01-262-001 (grupo A, Ing. de Sistemas, aula 01, semestre 2026-II, nº 1).
 *
 * El correlativo es independiente para cada combinación de grupo, carrera, aula
 * y semestre. Es compatible con MySQL y SQLite: el máximo se calcula en PHP, sin
 * funciones propias de un motor (como SUBSTRING_INDEX).
 */
class EnrollmentCodeService
{
    /**
     * Asigna el código, el grupo y el semestre a la matrícula, sin guardarla.
     *
     * Debe llamarse dentro de una transacción (DB::transaction): así el bloqueo
     * de lockForUpdate dura hasta el commit y el código se guarda junto con la
     * aprobación y su auditoría. El índice único de enrollment_code impide
     * duplicados aunque dos aprobaciones coincidan.
     *
     * @throws EnrollmentCodeException si falta la carrera, el aula o un semestre activo.
     */
    public function assign(Enrollment $enrollment): string
    {
        $career = Career::find($enrollment->career_id)
            ?? throw new EnrollmentCodeException('La matrícula no tiene carrera asignada.');
        $classroom = Classroom::find($enrollment->classroom_id)
            ?? throw new EnrollmentCodeException('Asigna un aula para generar el código de matrícula.');
        $semester = ($enrollment->semester_id ? Semester::find($enrollment->semester_id) : Semester::current())
            ?? throw new EnrollmentCodeException('No hay un semestre activo configurado.');

        $prefix = implode('-', [
            strtoupper($career->group),
            strtoupper($career->code),
            str_pad($classroom->number, 2, '0', STR_PAD_LEFT),
            $semester->code,
        ]).'-';

        $code = $prefix.str_pad((string) ($this->lastNumber($prefix) + 1), 3, '0', STR_PAD_LEFT);

        $enrollment->enrollment_code = $code;
        $enrollment->group = $career->group;
        $enrollment->semester_id ??= $semester->id;

        return $code;
    }

    /** Mayor correlativo ya usado con ese prefijo (0 si no hay ninguno). */
    private function lastNumber(string $prefix): int
    {
        return (int) Enrollment::where('enrollment_code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('enrollment_code')
            ->map(fn (string $code) => (int) substr($code, strlen($prefix)))
            ->max();
    }
}
