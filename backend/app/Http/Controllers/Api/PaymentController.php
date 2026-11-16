<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Http\Requests\Payment\ValidatePaymentRequest;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pagos. Los permisos están en App\Policies\PaymentPolicy, la validación
 * en App\Http\Requests\Payment\* y los cálculos en App\Services\PaymentService.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    /**
     * Listar pagos: admin/coordinador ven todos; el resto, solo los suyos (RF22).
     */
    public function index(Request $request)
    {
        $query = Payment::query();

        if (Gate::denies('viewAll', Payment::class)) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->paginate(20));
    }

    /** Registrar un pago (estudiante con perfil completo). */
    public function store(StorePaymentRequest $request)
    {
        $payment = $this->payments->register($request->user(), $request->validated());

        return response()->json($payment, 201);
    }

    public function show(Payment $payment)
    {
        Gate::authorize('view', $payment);

        return response()->json($payment);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment)
    {
        return response()->json($this->payments->correct($payment, $request->validated()));
    }

    public function destroy(Payment $payment)
    {
        Gate::authorize('delete', $payment);

        $payment->delete();

        return response()->json(['success' => true]);
    }

    /** Validar o rechazar el pago (admin o coordinador). Queda en audit_logs. */
    public function validatePayment(ValidatePaymentRequest $request, Payment $payment)
    {
        $payment = $this->payments->review(
            $payment,
            $request->user(),
            $request->validated('status'),
            $request->validated('rejection_reason'),
        );

        return response()->json($payment);
    }
}
