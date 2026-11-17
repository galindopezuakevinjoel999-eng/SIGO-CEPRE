<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Semester;
use App\Models\User;
use App\Services\PrivateFiles;

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

    /** Al eliminar la matrícula se borran sus documentos del disco privado. */
    protected static function booted(): void
    {
        static::deleted(fn (Enrollment $enrollment) => app(PrivateFiles::class)->delete($enrollment->documentPaths()));
    }

    /**
     * Documentos subidos en el paso 3: lista de {id, name, path, mime, size}.
     *
     * @return list<string>
     */
    public function documentPaths(): array
    {
        return collect($this->documents ?? [])->pluck('path')->filter()->values()->all();
    }

    /** @return array{id: string, name: string, path: string, mime: ?string, size: int}|null */
    public function findDocument(string $id): ?array
    {
        return collect($this->documents ?? [])->firstWhere('id', $id);
    }

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

    public function validatedPayments()
    {
        return $this->payments()->where('status', Payment::STATUS_VALIDATED);
    }

    /** Requisito para aprobar la matrícula: al menos un pago validado. */
    public function hasValidatedPayment(): bool
    {
        return $this->validatedPayments()->exists();
    }

    public function validatedPaymentsTotal(): float
    {
        return (float) $this->validatedPayments()->sum('amount');
    }
}
