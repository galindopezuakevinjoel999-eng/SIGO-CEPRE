<?php

namespace App\Http\Requests\Payment;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Registrar una cuota (RF17, RF18, RF20).
 *
 * El primer pago elige la modalidad (unico o fraccionado) y queda fija.
 * El monto debe ser exactamente el de la siguiente cuota de esa modalidad.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Payment::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('receipt_number')) {
            $this->merge(['receipt_number' => PaymentFields::normalizeReceiptNumber($this->input('receipt_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            // Todo pago pertenece a una matrícula, y solo a una propia.
            'enrollment_id' => [
                'required',
                Rule::exists('enrollments', 'id')->where('student_id', $this->user()->id),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'modality' => ['nullable', 'string', 'max:30'],
            ...PaymentFields::rules(required: true),
        ];
    }

    public function messages(): array
    {
        return [
            'enrollment_id.required' => 'Indica la matrícula que estás pagando.',
            'enrollment_id.exists' => 'La matrícula indicada no existe o no te pertenece.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            ...PaymentFields::messages(),
        ];
    }

    /** Reglas que dependen del ciclo, la modalidad y las cuotas ya registradas. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $payments = app(PaymentService::class);
                $enrollment = Enrollment::with('semester')->find($this->input('enrollment_id'));
                $plans = $payments->plansFor($enrollment->semester);

                if (! $plans) {
                    $validator->errors()->add('enrollment_id', 'Los montos de pago de este ciclo aún no están configurados. Comunícate con la administración.');

                    return;
                }

                $chosen = $enrollment->payment_modality;
                $requested = $this->input('modality');

                if (! $chosen && ! isset($plans[$requested])) {
                    $options = collect($plans)->map(fn ($p, $key) => "{$key} (S/ ".number_format($p['total'], 2).')')->implode(' o ');
                    $validator->errors()->add('modality', "Elige la modalidad de pago: {$options}.");

                    return;
                }

                if ($chosen && $requested && $requested !== $chosen) {
                    $validator->errors()->add('modality', "Ya elegiste la modalidad \"{$chosen}\" para esta matrícula; no se puede cambiar.");

                    return;
                }

                $modality = $chosen ?? $requested;

                if ($chosen && $payments->outstandingBalance($enrollment) <= 0) {
                    $validator->errors()->add('enrollment_id', 'Esta matrícula ya está pagada por completo.');

                    return;
                }

                $installment = $payments->nextInstallment($enrollment, $modality);

                if (! $installment) {
                    $validator->errors()->add('enrollment_id', 'Ya registraste todas las cuotas de tu modalidad. Espera a que sean validadas.');

                    return;
                }

                if (round((float) $this->input('amount'), 2) !== $installment['amount']) {
                    $validator->errors()->add('amount', sprintf(
                        'La cuota %d de %d (%s) es de S/ %s.',
                        $installment['number'],
                        $installment['total_installments'],
                        $plans[$modality]['label'],
                        number_format($installment['amount'], 2),
                    ));
                }
            },
        ];
    }
}
