<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Semester extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'year',
        'cycle',
        'name',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

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
