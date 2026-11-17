<?php

namespace App\Http\Requests\Payment;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Corregir un pago pendiente: método, N° de recibo o comprobante. El monto y
 * la matrícula no cambian (el monto es el de la cuota). El estado se revisa
 * con ValidatePaymentRequest (PUT /payments/{id}/validate).
 */
class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('payment'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('receipt_number')) {
            $this->merge(['receipt_number' => PaymentFields::normalizeReceiptNumber($this->input('receipt_number'))]);
        }
    }

    public function rules(): array
    {
        return PaymentFields::rules(required: false, ignorePaymentId: $this->route('payment')->id);
    }

    public function messages(): array
    {
        return PaymentFields::messages();
    }
}
