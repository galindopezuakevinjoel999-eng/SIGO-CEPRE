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

    /**
     * Documentos del paso 3 (requisitos del CEPRE UNAJMA).
     * tipo => [etiqueta, extensiones permitidas, obligatorio]
     */
    public const DOCUMENT_TYPES = [
        'recibo_pago' => ['label' => 'Recibo de pago', 'extensions' => ['jpg', 'jpeg', 'png', 'pdf'], 'required' => true],
        'foto_postulante' => ['label' => 'Foto del postulante', 'extensions' => ['jpg', 'jpeg', 'png'], 'required' => true],
        'dni' => ['label' => 'DNI', 'extensions' => ['pdf'], 'required' => true],
        'certificado_estudios' => ['label' => 'Certificado de estudios', 'extensions' => ['pdf'], 'required' => false],
    ];

    /** Estado del certificado de estudios. */
    public const CERTIFICATE_PENDING = 'pending';      // falta: "certificado pendiente de regularizar"

    public const CERTIFICATE_UPLOADED = 'uploaded';    // el estudiante lo subió

    public const CERTIFICATE_DELIVERED = 'delivered';  // la administración lo marcó como entregado

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
        // No asignables: los fija el sistema.
        //  submitted_at       al confirmar el paso 4.
        //  payment_modality   con el primer pago (PaymentService).
        //  certificate_*      según los documentos y la administración.
    ];

    protected $casts = [
        'documents' => 'array',
        'progress' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'submitted_at' => 'datetime',
        'certificate_delivered_at' => 'datetime',
    ];

    /** Al eliminar la matrícula se borran sus documentos del disco privado. */
    protected static function booted(): void
    {
        static::deleted(fn (Enrollment $enrollment) => app(PrivateFiles::class)->delete($enrollment->documentPaths()));
    }

    /** @return array{id: string, type: string, name: string, path: string, mime: ?string, size: int}|null */
    public function documentOfType(string $type): ?array
    {
        return collect($this->documents ?? [])->firstWhere('type', $type);
    }

    public function hasDocument(string $type): bool
    {
        return $this->documentOfType($type) !== null;
    }

    public function certificatePending(): bool
    {
        return $this->certificate_status === self::CERTIFICATE_PENDING;
    }

    /**
     * Documentos subidos en el paso 3: lista de {id, type, name, path, mime, size}.
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
