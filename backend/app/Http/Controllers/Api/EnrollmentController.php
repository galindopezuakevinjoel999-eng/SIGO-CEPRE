<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EnrollmentCodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\EnrollmentStepRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentStatusRequest;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Services\AuditLogger;
use App\Services\EnrollmentCodeService;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Matrículas. Los permisos están en App\Policies\EnrollmentPolicy y la
 * validación en App\Http\Requests\Enrollment\*.
 */
class EnrollmentController extends Controller
{
    public function __construct(private PrivateFiles $files) {}

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
        $this->storeDocuments($enrollment, $request->documentFiles(), $request->step());
        $this->markSubmitted($enrollment, $request->step());

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
        $this->storeDocuments($enrollment, $request->documentFiles(), $request->step());
        $this->markSubmitted($enrollment, $request->step());

        return response()->json($enrollment->load(['career', 'classroom', 'semester']));
    }

    /**
     * Al confirmar el paso 4 por primera vez, la matrícula queda enviada a
     * revisión (submitted_at). Si el estudiante la corrige después mientras
     * sigue pendiente, la fecha no cambia: el tiempo de atención
     * administrativa (indicador D1) se mide desde el primer envío.
     */
    private function markSubmitted(Enrollment $enrollment, int $step): void
    {
        if ($step === EnrollmentStepRequest::LAST_STEP && $enrollment->submitted_at === null) {
            $enrollment->forceFill(['submitted_at' => now()])->save();
        }
    }

    /** Descargar un documento de la matrícula (dueño, admin o coordinador). */
    public function downloadDocument(Enrollment $enrollment, string $document)
    {
        Gate::authorize('view', $enrollment);

        $file = $enrollment->findDocument($document) ?? abort(404, 'El documento no existe.');

        return $this->files->download($file['path'], $file['name']);
    }

    /**
     * Documentos por tipo: enviar uno reemplaza el anterior de ese tipo y
     * conserva los demás. Primero se guardan los nuevos y la matrícula;
     * después se borran los reemplazados del disco.
     *
     * @param  array<string, UploadedFile>  $files  tipo => archivo
     */
    private function storeDocuments(Enrollment $enrollment, array $files, int $step): void
    {
        $previous = [];

        if ($files) {
            $documents = collect($enrollment->documents ?? [])->keyBy('type');

            foreach ($files as $type => $file) {
                if ($old = $documents->get($type)) {
                    $previous[] = $old['path'];
                }

                $documents[$type] = [
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    ...$this->files->store($file, "enrollments/{$enrollment->id}"),
                ];
            }

            $enrollment->documents = $documents->values()->all();
        }

        $this->syncCertificateStatus($enrollment, $step);
        $enrollment->save();

        $this->files->delete($previous); // los reemplazados, después de guardar los nuevos
    }

    /**
     * Desde el paso 3: sin certificado de estudios, la matrícula queda con el
     * certificado "pendiente de regularizar"; si lo sube, queda "subido". Si la
     * administración ya lo marcó como entregado, no cambia.
     */
    private function syncCertificateStatus(Enrollment $enrollment, int $step): void
    {
        if ($step < 3 || $enrollment->certificate_status === Enrollment::CERTIFICATE_DELIVERED) {
            return;
        }

        $enrollment->certificate_status = $enrollment->hasDocument('certificado_estudios')
            ? Enrollment::CERTIFICATE_UPLOADED
            : Enrollment::CERTIFICATE_PENDING;
    }

    /**
     * La administración marca el certificado de estudios como entregado
     * (regularizado en físico). Queda en audit_logs.
     */
    public function markCertificateDelivered(Request $request, Enrollment $enrollment, AuditLogger $audit)
    {
        Gate::authorize('approve', $enrollment);

        if ($enrollment->certificate_status === Enrollment::CERTIFICATE_DELIVERED) {
            return response()->json(['message' => 'El certificado ya figura como entregado.'], 422);
        }

        DB::transaction(function () use ($request, $enrollment, $audit) {
            $previous = $enrollment->certificate_status;

            $enrollment->forceFill([
                'certificate_status' => Enrollment::CERTIFICATE_DELIVERED,
                'certificate_delivered_at' => now(),
                'certificate_delivered_by' => $request->user()->id,
            ])->save();

            $audit->record($request->user(), $enrollment->student_id, AuditLogger::CERTIFICATE_DELIVERED, null, [
                'enrollment_id' => $enrollment->id,
                'previous_status' => $previous,
            ]);
        });

        return response()->json($enrollment->only(['id', 'certificate_status', 'certificate_delivered_at', 'certificate_delivered_by']));
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
     * genera el código de matrícula.
     *
     * El cambio de estado, el código y el registro en audit_logs se guardan en
     * una sola transacción: si algo falla, no queda nada a medias.
     */
    public function updateStatus(
        UpdateEnrollmentStatusRequest $request,
        Enrollment $enrollment,
        AuditLogger $audit,
        EnrollmentCodeService $codes,
    ) {
        $data = $request->validated();
        $previousStatus = $enrollment->status;

        try {
            DB::transaction(function () use ($enrollment, $data, $previousStatus, $audit, $codes, $request) {
                $enrollment->status = $data['status'];

                // El administrador asigna el aula al aprobar (necesaria para el código de matrícula).
                if (! empty($data['classroom_id'])) {
                    $enrollment->classroom_id = $data['classroom_id'];
                }

                if ($data['status'] === 'approved') {
                    $enrollment->approved_at = now();
                    $enrollment->rejected_at = null;

                    if ($enrollment->career_id && $enrollment->classroom_id && ! $enrollment->enrollment_code) {
                        $codes->assign($enrollment);
                    }
                }

                if ($data['status'] === 'rejected') {
                    $enrollment->rejected_at = now();
                    $enrollment->approved_at = null;
                    $enrollment->rejection_reason = $data['rejection_reason'];
                }

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
                    array_filter([
                        'enrollment_id' => $enrollment->id,
                        'previous_status' => $previousStatus,
                        'new_status' => $data['status'],
                        'enrollment_code' => $enrollment->enrollment_code,
                    ]),
                );
            });
        } catch (EnrollmentCodeException $e) {
            return response()->json([
                'message' => 'No se pudo aprobar la matrícula: '.$e->getMessage(),
            ], 422);
        }

        return response()->json($enrollment->load(['career', 'classroom', 'semester', 'student']));
    }
}
