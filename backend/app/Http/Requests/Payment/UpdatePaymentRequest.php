<?php

namespace App\Http\Requests\Payment;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Corregir los datos de un pago. El estado no se cambia aquí: se valida con
 * ValidatePaymentRequest (PUT /payments/{id}/validate).
 */
class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('payment'));
    }

    public function rules(): array
    {
        return PaymentFields::rules(required: false);
    }
}
