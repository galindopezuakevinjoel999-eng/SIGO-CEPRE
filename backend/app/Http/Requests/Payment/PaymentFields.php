<?php

namespace App\Http\Requests\Payment;

/** Reglas de los datos de un pago, compartidas por el registro y la edición. */
final class PaymentFields
{
    public static function rules(bool $required): array
    {
        return [
            // Al editar, el monto puede omitirse, pero no vaciarse (la columna no admite null).
            'amount' => [$required ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string'],
            'receipt_url' => ['nullable', 'string'],
            'partial' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date'],
            'paid_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'balance' => ['nullable', 'numeric', 'min:0'],
            'installment_number' => ['nullable', 'integer', 'min:1'],
            'total_installments' => ['nullable', 'integer', 'min:1'],
            'installment_plan' => ['nullable', 'json'],
        ];
    }
}
