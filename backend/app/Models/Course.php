<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'career_id',
        'semester_id',
        'description',
        'credits',
        'max_students',
        'status',
    ];

    public function career()
    {
        return $this->belongsTo(Career::class);
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function materials()
    {
        return $this->hasMany(Material::class);
    }
}
