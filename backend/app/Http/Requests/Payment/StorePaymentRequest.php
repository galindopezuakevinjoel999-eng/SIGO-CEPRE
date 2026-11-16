<?php

namespace App\Http\Requests\Payment;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Todo pago pertenece a una matrícula, y solo a una propia.
            'enrollment_id' => [
                'required',
                Rule::exists('enrollments', 'id')->where('student_id', $this->user()->id),
            ],
            ...PaymentFields::rules(required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'enrollment_id.required' => 'Indica la matrícula que estás pagando.',
            'enrollment_id.exists' => 'La matrícula indicada no existe o no te pertenece.',
            ...PaymentFields::messages(),
        ];
    }

    /** Reglas que dependen del costo configurado y del saldo de la matrícula. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $payments = app(PaymentService::class);

                if ($payments->enrollmentCost() === null) {
                    $validator->errors()->add('enrollment_id', 'El costo de matrícula aún no está configurado. Comunícate con la administración.');

                    return;
                }

                $enrollment = Enrollment::find($this->input('enrollment_id'));

                if ($payments->outstandingBalance($enrollment) <= 0) {
                    $validator->errors()->add('enrollment_id', 'Esta matrícula ya está pagada por completo.');

                    return;
                }

                OverpaymentCheck::apply($validator, $payments->availableToRegister($enrollment), (float) $this->input('amount'));
            },
        ];
    }
}
