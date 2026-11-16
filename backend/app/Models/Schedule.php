<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'teacher_id',
        'career_id',
        'classroom_id',
        'semester_id',
        'days',
        'start_time',
        'end_time',
    ];

    protected $casts = [
        'days' => 'array',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }
}
