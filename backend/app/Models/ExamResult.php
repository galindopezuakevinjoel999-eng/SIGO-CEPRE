<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Resultado de un estudiante en un examen (RF32). "rank" es el puesto general
 * y "rank_in_group" el puesto dentro de su grupo (A, B o C); los empates
 * comparten puesto (1, 1, 3). Ver ExamRanking.
 */
class ExamResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'student_id', 'correct', 'wrong', 'blank', 'score', 'group', 'rank', 'rank_in_group',
    ];

    protected $casts = [
        'correct' => 'integer',
        'wrong' => 'integer',
        'blank' => 'integer',
        'score' => 'float',
        'rank' => 'integer',
        'rank_in_group' => 'integer',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
