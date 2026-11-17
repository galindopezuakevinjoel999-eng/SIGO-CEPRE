<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Examen del CEPRE (RF30): simulacro, parcial o final.
 *
 * Puntaje = correctas × points_correct + incorrectas × points_wrong + en blanco × points_blank.
 * Los puntos son el valor que se SUMA por respuesta: una penalización por
 * incorrecta se guarda en negativo (ej. -1.125).
 *
 * Solo se importan resultados y se editan los puntos mientras está en
 * borrador; publicado queda fijo y visible para los estudiantes (RF33).
 */
class Exam extends Model
{
    use HasFactory;

    public const TYPES = ['simulacro', 'parcial', 'final'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name', 'type', 'date', 'semester_id', 'group', 'total_questions',
        'points_correct', 'points_wrong', 'points_blank', 'status',
        'results_imported_at', 'published_at', 'published_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_questions' => 'integer',
        'points_correct' => 'float',
        'points_wrong' => 'float',
        'points_blank' => 'float',
        'results_imported_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function results()
    {
        return $this->hasMany(ExamResult::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function score(int $correct, int $wrong, int $blank): float
    {
        return round(
            $correct * $this->points_correct + $wrong * $this->points_wrong + $blank * $this->points_blank,
            3,
        );
    }
}
