<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo del CEPRE UNAJMA. Cada año hay tres: Intensivo, Ordinario I y
 * Ordinario II. Código: dos dígitos del año + número de ciclo
 * (2026 → 260 intensivo, 261 ordinario I, 262 ordinario II).
 *
 * @property ?array<string, array{label: string, installments: list<float>}> $payment_plans
 */
class Semester extends Model
{
    use HasFactory;

    /** tipo => [número de ciclo en el código, nombre] */
    public const TYPES = [
        'intensivo' => ['cycle' => 0, 'label' => 'Ciclo Intensivo'],
        'ordinario_i' => ['cycle' => 1, 'label' => 'Ciclo Ordinario I'],
        'ordinario_ii' => ['cycle' => 2, 'label' => 'Ciclo Ordinario II'],
    ];

    protected $fillable = [
        'code',
        'type',
        'year',
        'cycle',
        'name',
        'enrollment_start',
        'enrollment_end',
        'classes_start',
        'classes_end',
        'min_admission_score',
        'payment_plans',
        'is_active',
    ];

    protected $casts = [
        'enrollment_start' => 'date',
        'enrollment_end' => 'date',
        'classes_start' => 'date',
        'classes_end' => 'date',
        'min_admission_score' => 'float',
        'payment_plans' => 'array',
        'is_active' => 'boolean',
    ];

    /** "262" para el Ordinario II de 2026. */
    public static function codeFor(string $type, int $year): string
    {
        return sprintf('%02d%d', $year % 100, self::TYPES[$type]['cycle']);
    }

    /** "Ciclo Ordinario II 2026". */
    public static function nameFor(string $type, int $year): string
    {
        return self::TYPES[$type]['label'].' '.$year;
    }

    /**
     * Obtener el semestre activo actual.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    /** Carreras del ciclo con sus vacantes (pivot.vacancies). */
    public function careers()
    {
        return $this->belongsToMany(Career::class)->withPivot('vacancies')->withTimestamps();
    }

    /**
     * ¿Está abierta la inscripción? Compara fechas en la hora de Lima, con
     * ambos días incluidos. Sin periodo configurado, está cerrada.
     */
    public function isEnrollmentOpen(?Carbon $at = null): bool
    {
        if (! $this->enrollment_start || ! $this->enrollment_end) {
            return false;
        }

        $today = ($at ?? now())->copy()->setTimezone(config('app.display_timezone'))->toDateString();

        return $today >= $this->enrollment_start->toDateString() && $today <= $this->enrollment_end->toDateString();
    }

    /** "del 03/08/2026 al 21/08/2026" */
    public function enrollmentPeriodLabel(): ?string
    {
        return $this->enrollment_start && $this->enrollment_end
            ? "del {$this->enrollment_start->format('d/m/Y')} al {$this->enrollment_end->format('d/m/Y')}"
            : null;
    }

    /**
     * Activa este semestre como el único activo.
     *
     * Regla del CEPRE: cada semestre es independiente, como si el estudiante
     * fuera nuevo. Al empezar un semestre, todos los estudiantes vuelven a
     * 0 faltas y estado "activo". Las asistencias anteriores no se borran:
     * quedan como historial de su semestre (attendance → schedule → semester).
     *
     * Si el semestre ya estaba activo no hace nada, para no reiniciar las
     * faltas a mitad de ciclo.
     */
    public function activate(): void
    {
        if ($this->exists && $this->is_active && $this->isClean('is_active')) {
            return;
        }

        DB::transaction(function () {
            static::whereKeyNot($this->getKey())->where('is_active', true)->update(['is_active' => false]);

            $this->is_active = true;
            $this->save();

            User::where('role', User::ROLE_ESTUDIANTE)->update([
                'absence_count' => 0,
                'student_status' => 'activo',
            ]);
        });
    }
}
