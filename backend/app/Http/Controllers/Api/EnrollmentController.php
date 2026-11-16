<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Services\EnrollmentCodeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    /**
     * Listar matrículas. Admin/coordinador ve todas, estudiante solo las suyas.
     */
    public function index(Request $request)
    {
        $query = Enrollment::with(['career', 'classroom', 'semester', 'student']);

        if ($request->user()->isEstudiante()) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(20));
    }

    /**
     * Crear nueva solicitud de matrícula (solo estudiantes).
     * Soporta creación por pasos con campo current_step.
     */
    public function store(Request $request)
    {
        if (! $request->user()->isEstudiante()) {
            return response()->json(['message' => 'Solo los estudiantes pueden solicitar matrícula'], 403);
        }

        // Verificar que no tenga matrícula activa en el semestre actual
        $activeSemester = \App\Models\Semester::current();
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

        $step = $request->input('step', 1);

        // Validación por paso
        $rules = $this->getStepValidationRules($step);
        $data = $request->validate($rules);

        $data['student_id'] = $request->user()->id;
        $data['status'] = 'pending';
        $data['current_step'] = $step;

        if ($activeSemester && empty($data['semester_id'])) {
            $data['semester_id'] = $activeSemester->id;
        }

        $enrollment = Enrollment::create($data);

        return response()->json($enrollment->load(['career', 'classroom', 'semester']), 201);
    }

    /**
     * Ver una matrícula específica.
     */
    public function show(Request $request, Enrollment $enrollment)
    {
        if ($request->user()->isEstudiante() && $enrollment->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($enrollment->load(['career', 'classroom', 'semester', 'student']));
    }

    /**
     * Actualizar matrícula (estudiante actualiza su progreso, staff cambia status).
     */
    public function update(Request $request, Enrollment $enrollment)
    {
        $user = $request->user();

        if ($user->isEstudiante() && $enrollment->student_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Estudiantes solo pueden editar si está pendiente
        if ($user->isEstudiante() && $enrollment->status !== 'pending') {
            return response()->json(['message' => 'No puedes editar una matrícula que ya fue procesada'], 422);
        }

        $step = $request->input('step', $enrollment->current_step);
        $rules = $this->getStepValidationRules($step, false);

        // Solo admin y coordinador pueden cambiar el status desde esta ruta
        if ($user->isAdmin() || $user->isCoordinador()) {
            $rules['status'] = ['nullable', Rule::in(['pending', 'approved', 'rejected'])];
        }

        $data = $request->validate($rules);
        $data['current_step'] = $step;

        $enrollment->update($data);

        return response()->json($enrollment->load(['career', 'classroom', 'semester']));
    }

    /**
     * Eliminar matrícula (solo si está pendiente).
     */
    public function destroy(Request $request, Enrollment $enrollment)
    {
        $user = $request->user();

        if ($user->isEstudiante() && $enrollment->student_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($enrollment->status !== 'pending') {
            return response()->json(['message' => 'Solo se pueden eliminar matrículas pendientes'], 422);
        }

        $enrollment->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Aprobar o rechazar matrícula (admin o coordinador).
     * Al aprobar con pago confirmado, genera código de matrícula.
     */
    public function updateStatus(Request $request, Enrollment $enrollment)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $enrollment->status = $data['status'];

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
        }

        $enrollment->save();

        return response()->json($enrollment->load(['career', 'classroom', 'semester', 'student']));
    }

    /**
     * Reglas de validación por paso del formulario.
     */
    private function getStepValidationRules(int $step, bool $required = true): array
    {
        $req = $required ? 'required' : 'nullable';

        return match ($step) {
            // Paso 1: Verificar perfil (no necesita datos extra, el frontend verifica)
            1 => [
                'career_id' => ['nullable', 'exists:careers,id'],
                'documents' => ['nullable', 'array'],
                'progress' => ['nullable', 'array'],
            ],
            // Paso 2: Seleccionar carrera
            2 => [
                'career_id' => [$req, 'exists:careers,id'],
                'documents' => ['nullable', 'array'],
                'progress' => ['nullable', 'array'],
            ],
            // Paso 3: Subir documentos
            3 => [
                'career_id' => [$req, 'exists:careers,id'],
                'documents' => [$req, 'array'],
                'progress' => ['nullable', 'array'],
            ],
            // Paso 4: Confirmación final
            4 => [
                'career_id' => [$req, 'exists:careers,id'],
                'documents' => [$req, 'array'],
                'progress' => ['nullable', 'array'],
                'classroom_id' => ['nullable', 'exists:classrooms,id'],
                'semester_id' => ['nullable', 'exists:semesters,id'],
            ],
            default => [
                'career_id' => ['nullable', 'exists:careers,id'],
                'documents' => ['nullable', 'array'],
                'progress' => ['nullable', 'array'],
            ],
        };
    }
}
