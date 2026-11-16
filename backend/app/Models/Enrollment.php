<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Semester;
use App\Models\User;

class Enrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'career_id',
        'classroom_id',
        'semester_id',
        'enrollment_code',
        'group',
        'status',
        'documents',
        'progress',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'current_step',
    ];

    protected $casts = [
        'documents' => 'array',
        'progress' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
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

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
