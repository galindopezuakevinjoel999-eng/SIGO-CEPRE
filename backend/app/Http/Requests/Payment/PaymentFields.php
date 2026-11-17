<?php

namespace App\Http\Requests\Payment;

use App\Services\PaymentService;
use App\Services\PrivateFiles;

/**
 * Campos de un pago que envía el estudiante, compartidos por el registro y la
 * corrección: monto, método, comprobante (archivo) y número de cuota. El resto
 * (balance, installment_plan, paid_date, partial, estado) lo asigna
 * PaymentService; cualquier otro campo enviado se ignora.
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
            // Comprobante (multipart). Obligatorio al registrar; al corregir, reemplaza al anterior.
            'receipt' => [$required ? 'required' : 'sometimes', ...PrivateFiles::DOCUMENT_RULES],
            'installment_number' => ['nullable', 'integer', 'between:1,'.$installments],
        ];
    }

    public static function messages(): array
    {
        return [
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'installment_number.between' => 'El número de cuota debe estar entre :min y :max.',
            'receipt.required' => 'Adjunta el comprobante de pago.',
            'receipt.file' => 'El comprobante debe ser un archivo.',
            'receipt.mimes' => 'El comprobante debe ser PDF, JPG o PNG.',
            'receipt.extensions' => 'El comprobante debe ser PDF, JPG o PNG.',
            'receipt.max' => 'El comprobante puede pesar como máximo 5 MB.',
        ];
    }
}
