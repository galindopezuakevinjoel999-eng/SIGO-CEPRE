<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\PrivateFiles;

class Payment extends Model
{
    use HasFactory;

    // Estados del pago (RF19).
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'student_id',
        'enrollment_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'receipt_path',
        'receipt_name',
        'partial',
        'due_date',
        'paid_date',
        'notes',
        'balance',
        'installment_number',
        'total_installments',
        'installment_plan',
        'validated_by',
        'validated_at',
        'rejection_reason',
    ];

    protected $casts = [
        'partial' => 'boolean',
        'due_date' => 'date',
        'paid_date' => 'date',
        'installment_plan' => 'array',
        'validated_at' => 'datetime',
    ];

    /** Al eliminar el pago se borra su comprobante del disco privado. */
    protected static function booted(): void
    {
        static::deleted(fn (Payment $payment) => app(PrivateFiles::class)->delete($payment->receipt_path));
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** Admin o coordinador que validó o rechazó el pago. */
    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
