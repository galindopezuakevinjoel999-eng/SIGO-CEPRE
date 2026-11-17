<?php

namespace App\Models;

use App\Services\PrivateFiles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'uploaded_by',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_size',
        'type',
        'status',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    /** Al eliminar el material se borra su archivo del disco privado. */
    protected static function booted(): void
    {
        static::deleted(fn (Material $material) => app(PrivateFiles::class)->delete($material->file_path));
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
