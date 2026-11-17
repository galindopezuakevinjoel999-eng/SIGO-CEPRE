<?php

namespace App\Http\Requests\Payment;

use App\Services\PrivateFiles;
use Illuminate\Validation\Rule;

/**
 * Campos de un pago que envía el estudiante, compartidos por el registro y la
 * corrección: método, comprobante (archivo) y N° de recibo de caja. El monto
 * y la modalidad solo se envían al registrar (StorePaymentRequest); el resto
 * lo asigna PaymentService.
 */
final class PaymentFields
{
    /** N° de recibo de caja UNAJMA: letras, números y guiones. */
    public const RECEIPT_NUMBER_PATTERN = '/^[A-Z0-9][A-Z0-9-]{2,29}$/';

    public static function rules(bool $required, ?int $ignorePaymentId = null): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'payment_method' => ['nullable', 'string', 'max:50'],
            // Comprobante (multipart). Obligatorio al registrar; al corregir, reemplaza al anterior.
            'receipt' => [$req, ...PrivateFiles::DOCUMENT_RULES],
            // Único en todo el sistema (también frente a pagos rechazados).
            'receipt_number' => [
                $req, 'string', 'regex:'.self::RECEIPT_NUMBER_PATTERN,
                Rule::unique('payments', 'receipt_number')->ignore($ignorePaymentId),
            ],
        ];
    }

    public static function messages(): array
    {
        return [
            'receipt.required' => 'Adjunta el comprobante de pago.',
            'receipt.file' => 'El comprobante debe ser un archivo.',
            'receipt.mimes' => 'El comprobante debe ser PDF, JPG o PNG.',
            'receipt.extensions' => 'El comprobante debe ser PDF, JPG o PNG.',
            'receipt.max' => 'El comprobante puede pesar como máximo 5 MB.',
            'receipt_number.required' => 'Indica el N° del recibo de caja de la UNAJMA.',
            'receipt_number.regex' => 'El N° de recibo solo puede tener letras, números y guiones (3 a 30 caracteres).',
            'receipt_number.unique' => 'El recibo N° :input ya fue registrado en otro pago. Cada recibo de caja se usa una sola vez.',
        ];
    }

    /** Normaliza el N° de recibo antes de validar: sin espacios y en mayúsculas. */
    public static function normalizeReceiptNumber(mixed $value): mixed
    {
        return is_string($value) ? strtoupper(preg_replace('/\s+/', '', $value)) : $value;
    }
}
