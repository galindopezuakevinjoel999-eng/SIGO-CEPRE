<?php

namespace App\Http\Requests\Payment;

use App\Services\PaymentService;

/**
 * Campos de un pago que envía el estudiante, compartidos por el registro y la
 * corrección. El resto (balance, installment_plan, paid_date, partial, estado)
 * lo asigna PaymentService; cualquier otro campo enviado se ignora.
 */
final class PaymentFields
{
    public static function rules(bool $required): array
    {
        $installments = app(PaymentService::class)->installmentCount();

        return [
            // Al corregir, el monto puede omitirse, pero no vaciarse.
            'amount' => [$required ? 'required' : 'sometimes', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'receipt_url' => ['nullable', 'string', 'max:2048'],
            'installment_number' => ['nullable', 'integer', 'between:1,'.$installments],
        ];
    }

    public static function messages(): array
    {
        return [
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'installment_number.between' => 'El número de cuota debe estar entre :min y :max.',
        ];
    }
}
