<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Http\Requests\Payment\ValidatePaymentRequest;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pagos. Los permisos están en App\Policies\PaymentPolicy y la validación
 * en App\Http\Requests\Payment\*.
 */
class PaymentController extends Controller
{
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
        $payment = Payment::create([
            ...$request->validated(),
            'student_id' => $request->user()->id,
            'status' => 'pending', // todo pago nace pendiente hasta que se valida (RF19)
        ]);

        return response()->json($payment, 201);
    }

    public function show(Payment $payment)
    {
        Gate::authorize('view', $payment);

        return response()->json($payment);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment)
    {
        $payment->update($request->validated());

        return response()->json($payment);
    }

    public function destroy(Payment $payment)
    {
        Gate::authorize('delete', $payment);

        $payment->delete();

        return response()->json(['success' => true]);
    }

    /** Validar el pago: cambia su estado (admin o coordinador). */
    public function validatePayment(ValidatePaymentRequest $request, Payment $payment)
    {
        $payment->update($request->validated());

        return response()->json($payment);
    }
}
