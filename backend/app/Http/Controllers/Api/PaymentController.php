<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query();

        if (! $request->user()->isAdmin()) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isEstudiante()) {
            return response()->json(['message' => 'Solo los estudiantes pueden registrar pagos'], 403);
        }

        $data = $request->validate([
            'enrollment_id' => ['nullable', 'exists:enrollments,id'],
            'amount' => ['required', 'numeric', 'min:0'],
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
        ]);

        $data['student_id'] = $request->user()->id;
        $payment = Payment::create($data);

        return response()->json($payment, 201);
    }

    public function show(Request $request, Payment $payment)
    {
        if (! $request->user()->isAdmin() && $payment->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($payment);
    }

    public function update(Request $request, Payment $payment)
    {
        if (! $request->user()->isAdmin() && $payment->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rules = [
            'amount' => ['nullable', 'numeric', 'min:0'],
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

        if ($request->user()->isAdmin()) {
            $rules['status'] = ['nullable', Rule::in(['pending', 'completed', 'failed', 'refunded'])];
        }

        $data = $request->validate($rules);

        if (! $request->user()->isAdmin()) {
            unset($data['status']);
        }

        $payment->update($data);

        return response()->json($payment);
    }

    public function destroy(Request $request, Payment $payment)
    {
        if (! $request->user()->isAdmin() && $payment->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payment->delete();

        return response()->json(['success' => true]);
    }

    public function validatePayment(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'completed', 'failed', 'refunded'])],
        ]);

        $payment->update($data);

        return response()->json($payment);
    }
}
