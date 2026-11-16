<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentStatusRequest;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Services\AuditLogger;
use App\Services\EnrollmentCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Matrículas. Los permisos están en App\Policies\EnrollmentPolicy y la
 * validación en App\Http\Requests\Enrollment\*.
 */
class EnrollmentController extends Controller
{
    /**
     * Listar matrículas: admin/coordinador ven todas; el resto, solo las suyas.
     */
    public function index(Request $request)
    {
        $query = Enrollment::with(['career', 'classroom', 'semester', 'student']);

        if (Gate::denies('viewAll', Enrollment::class)) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(20));
    }

    /**
     * Crear la solicitud de matrícula (estudiante con perfil completo).
     * Soporta creación por pasos con el campo "step".
     */
    public function store(StoreEnrollmentRequest $request)
    {
        // Una sola matrícula activa o pendiente por semestre.
        $activeSemester = Semester::current();
        if ($activeSemester) {
            $existing = Enrollment::where('student_id', $request->user()->id)
                ->where('semester_id', $activeSemester->id)
                ->whereIn('status', ['pending', 'approved'])
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'Ya tienes una matrícula activa o pendiente para este semestre',
                    'enrollment' => $existing->load(['career', 'classroom', 'semester']),
                ], 422);
            }
        }

        $data = $request->enrollmentData();
        $data['student_id'] = $request->user()->id;
        $data['status'] = 'pending';

        if ($activeSemester && empty($data['semester_id'])) {
            $data['semester_id'] = $activeSemester->id;
        }

        $enrollment = Enrollment::create($data);

        return response()->json($enrollment->load(['career', 'classroom', 'semester']), 201);
    }

    public function show(Enrollment $enrollment)
    {
        Gate::authorize('view', $enrollment);

        return response()->json($enrollment->load(['career', 'classroom', 'semester', 'student']));
    }

    /**
     * El estudiante guarda el avance de su matrícula pendiente (RF11).
     * El estado se cambia solo con updateStatus().
     */
    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment)
    {
        $enrollment->update($request->enrollmentData());

        return response()->json($enrollment->load(['career', 'classroom', 'semester']));
    }

    public function destroy(Enrollment $enrollment)
    {
        Gate::authorize('delete', $enrollment);

        $enrollment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Aprobar o rechazar matrícula (admin o coordinador). Aprobar exige un pago
     * validado (UpdateEnrollmentStatusRequest). Con carrera y aula asignadas,
     * genera el código de matrícula. Cada decisión queda en audit_logs.
     */
    public function updateStatus(UpdateEnrollmentStatusRequest $request, Enrollment $enrollment, AuditLogger $audit)
    {
        $data = $request->validated();
        $previousStatus = $enrollment->status;

        $enrollment->status = $data['status'];

        // El administrador asigna el aula al aprobar (necesaria para el código de matrícula).
        if (! empty($data['classroom_id'])) {
            $enrollment->classroom_id = $data['classroom_id'];
        }

        if ($data['status'] === 'approved') {
            $enrollment->approved_at = now();
            $enrollment->rejected_at = null;

            // Generar código de matrícula si tiene carrera y aula asignada
            if ($enrollment->career_id && $enrollment->classroom_id && ! $enrollment->enrollment_code) {
                try {
                    $codeService = new EnrollmentCodeService();
                    $codeService->generate($enrollment);
                } catch (\Exception $e) {
                    return response()->json([
                        'message' => 'Matrícula aprobada pero no se pudo generar el código: '.$e->getMessage(),
                    ], 500);
                }
            }
        }

        if ($data['status'] === 'rejected') {
            $enrollment->rejected_at = now();
            $enrollment->approved_at = null;
            $enrollment->rejection_reason = $data['rejection_reason'];
        }

        DB::transaction(function () use ($enrollment, $audit, $request, $data, $previousStatus) {
            $enrollment->save();

            $audit->record(
                $request->user(),
                $enrollment->student_id,
                match ($data['status']) {
                    'approved' => AuditLogger::ENROLLMENT_APPROVED,
                    'rejected' => AuditLogger::ENROLLMENT_REJECTED,
                    default => AuditLogger::ENROLLMENT_REOPENED,
                },
                $data['rejection_reason'] ?? null,
                [
                    'enrollment_id' => $enrollment->id,
                    'previous_status' => $previousStatus,
                    'new_status' => $data['status'],
                ],
            );
        });

        return response()->json($enrollment->load(['career', 'classroom', 'semester', 'student']));
    }
}
