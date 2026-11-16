<?php

namespace App\Http\Requests\Enrollment;

use App\Models\Enrollment;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class StoreEnrollmentRequest extends EnrollmentStepRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Enrollment::class);
    }

    protected function defaultStep(): int
    {
        return self::FIRST_STEP;
    }

    protected function requiredFor(string $field): string
    {
        return 'required';
    }
}
