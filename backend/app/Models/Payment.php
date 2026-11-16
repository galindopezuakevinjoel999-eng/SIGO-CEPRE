<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Enrollment;
use App\Models\User;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'enrollment_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'receipt_url',
        'partial',
        'due_date',
        'paid_date',
        'notes',
        'balance',
        'installment_number',
        'total_installments',
        'installment_plan',
    ];

    protected $casts = [
        'partial' => 'boolean',
        'due_date' => 'date',
        'paid_date' => 'date',
        'installment_plan' => 'array',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class);
    }
}
