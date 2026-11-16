<?php

namespace App\Http\Requests\Payment;

use Illuminate\Validation\Validator;

/**
 * Rechaza un monto mayor que lo que aún se puede pagar:
 * costo − pagos validados − pagos pendientes de validación.
 * Lo usan el registro y la corrección de pagos.
 */
final class OverpaymentCheck
{
    public static function apply(Validator $validator, float $available, float $amount): void
    {
        if ($available <= 0) {
            $validator->errors()->add('amount', 'Tus pagos pendientes de validación ya cubren el saldo de esta matrícula. Espera a que sean revisados.');
        } elseif ($amount > $available) {
            $validator->errors()->add('amount', 'El monto supera lo que falta pagar (S/ '.number_format($available, 2).'), considerando tus pagos pendientes de validación.');
        }
    }
}
