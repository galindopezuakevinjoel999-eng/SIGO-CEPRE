<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Registrar un pago de matrícula (RF17, RF18, RF20). */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Payment::class);
    }

    public function rules(): array
    {
        return [
            // Solo se puede pagar una matrícula propia.
            'enrollment_id' => [
                'nullable',
                Rule::exists('enrollments', 'id')->where('student_id', $this->user()->id),
            ],
            ...PaymentFields::rules(required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'enrollment_id.exists' => 'La matrícula indicada no existe o no te pertenece.',
        ];
    }
}
