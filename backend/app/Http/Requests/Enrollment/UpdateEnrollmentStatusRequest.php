<?php

namespace App\Http\Requests\Enrollment;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Aprobar o rechazar una matrícula (RF16). */
class UpdateEnrollmentStatusRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('approve', $this->route('enrollment'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'rejection_reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required_if' => 'Indica el motivo del rechazo.',
        ];
    }

    /** No se aprueba una matrícula sin al menos un pago validado. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty()
                    && $this->input('status') === 'approved'
                    && ! $this->route('enrollment')->hasValidatedPayment()) {
                    $validator->errors()->add('status', 'No se puede aprobar la matrícula: aún no tiene ningún pago validado.');
                }
            },
        ];
    }
}
