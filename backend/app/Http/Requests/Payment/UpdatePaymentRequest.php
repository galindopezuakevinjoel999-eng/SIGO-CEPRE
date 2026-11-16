<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Corregir un pago pendiente. No cambia la matrícula ni el estado: el estado
 * se revisa con ValidatePaymentRequest (PUT /payments/{id}/validate).
 */
class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->payment());
    }

    public function rules(): array
    {
        return PaymentFields::rules(required: false);
    }

    public function messages(): array
    {
        return PaymentFields::messages();
    }

    /** Subir el monto tampoco puede generar un sobrepago. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $payments = app(PaymentService::class);
                $enrollment = $this->payment()->enrollment;

                if ($validator->errors()->isNotEmpty()
                    || ! $this->has('amount')
                    || ! $enrollment
                    || $payments->enrollmentCost() === null) {
                    return;
                }

                // Sin contar este mismo pago: se reemplaza su monto anterior.
                $available = $payments->availableToRegister($enrollment, except: $this->payment());

                OverpaymentCheck::apply($validator, $available, (float) $this->input('amount'));
            },
        ];
    }

    private function payment(): Payment
    {
        return $this->route('payment');
    }
}
