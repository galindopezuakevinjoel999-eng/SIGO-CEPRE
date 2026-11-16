<?php

namespace App\Http\Requests\Payment;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Validar un pago: admin o coordinador cambian su estado (RF19, RF21). */
class ValidatePaymentRequest extends FormRequest
{
    public const STATUSES = ['pending', 'completed', 'failed', 'refunded'];

    public function authorize(): Response
    {
        return Gate::inspect('validate', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(self::STATUSES)],
        ];
    }
}
