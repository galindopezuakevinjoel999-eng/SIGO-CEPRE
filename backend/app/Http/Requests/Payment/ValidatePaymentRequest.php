<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Revisar un pago: admin o coordinador lo validan o rechazan (RF19, RF21). */
class ValidatePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('validate', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([Payment::STATUS_VALIDATED, Payment::STATUS_REJECTED])],
            'rejection_reason' => ['required_if:status,'.Payment::STATUS_REJECTED, 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'El estado debe ser "validated" o "rejected".',
            'rejection_reason.required_if' => 'Indica el motivo del rechazo del pago.',
        ];
    }
}
