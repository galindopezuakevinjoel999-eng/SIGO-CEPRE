<?php

namespace App\Http\Requests\Payment;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Revisar un pago: admin o coordinador lo validan o rechazan (RF19, RF21). */
class ValidatePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('validate', $this->payment());
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

    /**
     * Validar no puede dejar la matrícula sobrepagada: el monto debe caber en el
     * saldo real (costo − otros pagos ya validados). Rechazar siempre se permite.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $payment = $this->payment();

                if ($validator->errors()->isNotEmpty()
                    || $this->input('status') !== Payment::STATUS_VALIDATED
                    || ! $payment->enrollment) {
                    return;
                }

                $payments = app(PaymentService::class);

                if ($payments->enrollmentCost() === null) {
                    $validator->errors()->add('status', 'El costo de matrícula no está configurado: no se puede calcular el saldo.');

                    return;
                }

                $balance = $payments->outstandingBalance($payment->enrollment, except: $payment);

                if ((float) $payment->amount > $balance) {
                    $validator->errors()->add('status', sprintf(
                        'No se puede validar: el pago (S/ %s) excede el saldo real de la matrícula (S/ %s). Recházalo o pide al estudiante que lo corrija.',
                        number_format((float) $payment->amount, 2),
                        number_format($balance, 2),
                    ));
                }
            },
        ];
    }

    private function payment(): Payment
    {
        return $this->route('payment');
    }
}
