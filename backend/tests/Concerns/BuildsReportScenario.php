<?php

namespace Tests\Concerns;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentService;

/**
 * Escenario fijo para probar dashboard, reportes e indicadores con valores
 * calculados a mano. Costo de matrícula: S/ 300. Umbrales de faltas: 2 y 3.
 *
 * Semestre 2026-II (activo). Tiempos: registro (creación → envío) + atención (envío → aprobación):
 *  e1 Ana   SIS (A) aula 01  aprobada  6 h + 18 h    pagos: 300 validado (12 h)                 → pagado
 *  e2 Luis  SIS (A) aula 01  aprobada  0,5 h + 11,5 h pagos: 100 validado (24 h) + 50 pendiente → parcial, saldo 200
 *  e3 Rosa  PSI (B) aula 02  aprobada  24 h + 24 h   pagos: 80 rechazado "Comprobante ilegible" → sin pago, saldo 300
 *  e4 Jhon  ADM (C) sin aula pendiente 2 h           pagos: 120 rechazado "comprobante  ilegible" → sin pago, saldo 300
 *  e5 Mia   ADM (C)          rechazada 0,25 h "Documentos incompletos"
 *  Asistencia: Ana P,P,T,F (75 %) · Luis F,F,P (33,3 %, en riesgo) · Rosa F,F,F (0 %, inhabilitada)
 *  Exámenes publicados: Simulacro 1 (01/09) y Simulacro 2 (01/10, el último); Simulacro 3 en borrador.
 *  Auditoría: falta el registro de la aprobación de e3, del rechazo del pago de e4 y de la
 *  publicación del Simulacro 1.
 *
 * Semestre 2026-I (ruido que NO debe contarse): una matrícula aprobada con pago de 999,
 * 5 faltas de Ana y un registro de auditoría.
 */
trait BuildsReportScenario
{
    protected Semester $semester;

    protected Semester $previousSemester;

    protected User $admin;

    protected User $coordinator;

    /** @var array<string, User> */
    protected array $students = [];

    /** @var array<string, Enrollment> */
    protected array $enrollments = [];

    /** @var array<string, Payment> */
    protected array $payments = [];

    /** @var array<string, Exam> */
    protected array $exams = [];

    protected array $careersByCode = [];

    protected array $classroomsByNumber = [];

    protected function buildReportScenario(): void
    {
        SystemSetting::setValue(PaymentService::COST_SETTING, '300', 'decimal');
        SystemSetting::setValue('absence_warning_1', '2', 'integer');
        SystemSetting::setValue('max_absences', '3', 'integer');

        $this->admin = User::factory()->admin()->create(['name' => 'Admin CEPRE']);
        $this->coordinator = User::factory()->coordinator()->create(['name' => 'Coordinación']);

        $this->previousSemester = Semester::factory()->create(['code' => '261', 'year' => 2026, 'cycle' => 1, 'name' => '2026-I']);
        $this->semester = Semester::factory()->active()->create(['code' => '262', 'year' => 2026, 'cycle' => 2, 'name' => '2026-II']);

        foreach ([['SIS', 'Ingeniería de Sistemas', 'A'], ['PSI', 'Psicología', 'B'], ['ADM', 'Administración de Empresas', 'C']] as [$code, $name, $group]) {
            $this->careersByCode[$code] = Career::factory()->create(['code' => $code, 'name' => $name, 'group' => $group]);
        }
        foreach (['01', '02'] as $number) {
            $this->classroomsByNumber[$number] = Classroom::factory()->create(['number' => $number, 'name' => "Aula {$number}"]);
        }

        // ── Matrículas ──
        $this->enroll('ana', 'Ana Quispe', '71000001', 'SIS', '01', 'approved', '2026-08-01 08:00', '2026-08-02 08:00', 'A-SIS-01-262-001', submittedAt: '2026-08-01 14:00');
        $this->enroll('luis', 'Luis Ccoica', '71000002', 'SIS', '01', 'approved', '2026-08-01 10:00', '2026-08-01 22:00', 'A-SIS-01-262-002', submittedAt: '2026-08-01 10:30');
        $this->enroll('rosa', 'Rosa Palomino', '71000003', 'PSI', '02', 'approved', '2026-08-03 00:00', '2026-08-05 00:00', 'B-PSI-02-262-001', submittedAt: '2026-08-04 00:00');
        $this->enroll('jhon', 'Jhon Vargas', '71000004', 'ADM', null, 'pending', '2026-08-04 09:00', submittedAt: '2026-08-04 11:00');
        $this->enroll('mia', 'Mia Huamán', '71000005', 'ADM', null, 'rejected', '2026-08-04 10:00', rejectionReason: 'Documentos incompletos', submittedAt: '2026-08-04 10:15');

        // ── Pagos ──
        $this->pay('ana', 'ana_full', 300, Payment::STATUS_VALIDATED, '2026-08-01 09:00', '2026-08-01 21:00', partial: false);
        $this->pay('luis', 'luis_partial', 100, Payment::STATUS_VALIDATED, '2026-08-02 10:00', '2026-08-03 10:00', partial: true);
        $this->pay('luis', 'luis_pending', 50, Payment::STATUS_PENDING, '2026-08-06 10:00');
        $this->pay('rosa', 'rosa_rejected', 80, Payment::STATUS_REJECTED, '2026-08-03 00:00', '2026-08-03 06:00', reason: 'Comprobante ilegible');
        $this->pay('jhon', 'jhon_rejected', 120, Payment::STATUS_REJECTED, '2026-08-04 09:30', '2026-08-04 12:00', reason: 'comprobante  ilegible');

        // ── Asistencia (umbrales 2 y 3) ──
        $sis = Schedule::factory()->for($this->semester)->create(['career_id' => $this->careersByCode['SIS']->id, 'classroom_id' => $this->classroomsByNumber['01']->id]);
        $psi = Schedule::factory()->for($this->semester)->create(['career_id' => $this->careersByCode['PSI']->id, 'classroom_id' => $this->classroomsByNumber['02']->id]);
        $this->attend('ana', $sis, ['present', 'present', 'late', 'absent']);
        $this->attend('luis', $sis, ['absent', 'absent', 'present']);
        $this->attend('rosa', $psi, ['absent', 'absent', 'absent']);

        // ── Exámenes ──
        $this->exams['s1'] = Exam::factory()->published()->for($this->semester)->create(['name' => 'Simulacro 1', 'date' => '2026-09-01', 'results_imported_at' => now()]);
        $this->exams['s2'] = Exam::factory()->published()->for($this->semester)->create(['name' => 'Simulacro 2', 'date' => '2026-10-01', 'results_imported_at' => now()]);
        $this->exams['s3'] = Exam::factory()->for($this->semester)->create(['name' => 'Simulacro 3 (borrador)', 'date' => '2026-11-01']);
        $this->examResult('s1', 'ana', 10, 2, 1);
        $this->examResult('s1', 'luis', 15, 1, 1);
        $this->examResult('s1', 'rosa', 5, 3, 1);
        $this->examResult('s2', 'ana', 30, 1, 1);
        $this->examResult('s2', 'luis', 20, 2, 2);
        $this->examResult('s2', 'rosa', 30, 1, 1);
        $this->examResult('s3', 'ana', 99, 1, 1); // borrador: no cuenta

        // ── Auditoría (incompleta a propósito) ──
        $this->audit($this->admin, AuditLogger::ENROLLMENT_APPROVED, ['enrollment_id' => $this->enrollments['ana']->id]);
        $this->audit($this->admin, AuditLogger::ENROLLMENT_APPROVED, ['enrollment_id' => $this->enrollments['luis']->id]);
        $this->audit($this->coordinator, AuditLogger::ENROLLMENT_REJECTED, ['enrollment_id' => $this->enrollments['mia']->id], 'Documentos incompletos');
        $this->audit($this->admin, AuditLogger::PAYMENT_VALIDATED, ['payment_id' => $this->payments['ana_full']->id]);
        $this->audit($this->admin, AuditLogger::PAYMENT_VALIDATED, ['payment_id' => $this->payments['luis_partial']->id]);
        $this->audit($this->coordinator, AuditLogger::PAYMENT_REJECTED, ['payment_id' => $this->payments['rosa_rejected']->id], 'Comprobante ilegible');
        $this->audit($this->admin, AuditLogger::EXAM_RESULTS_IMPORTED, ['exam_id' => $this->exams['s1']->id, 'total_rows' => 3, 'imported' => 3, 'not_found' => 0, 'errors' => 0]);
        $this->audit($this->admin, AuditLogger::EXAM_RESULTS_IMPORTED, ['exam_id' => $this->exams['s2']->id, 'total_rows' => 5, 'imported' => 3, 'not_found' => 1, 'errors' => 1]);
        $this->audit($this->admin, AuditLogger::EXAM_PUBLISHED, ['exam_id' => $this->exams['s2']->id]);

        // ── Ruido del semestre anterior ──
        $old = Enrollment::factory()->for($this->students['ana'], 'student')->create([
            'semester_id' => $this->previousSemester->id, 'career_id' => $this->careersByCode['SIS']->id, 'status' => 'approved',
            'created_at' => '2026-03-01 08:00', 'approved_at' => '2026-03-20 08:00',
        ]);
        Payment::factory()->for($this->students['ana'], 'student')->for($old)->create(['amount' => 999, 'status' => Payment::STATUS_VALIDATED, 'validated_at' => '2026-03-02']);
        $oldSchedule = Schedule::factory()->for($this->previousSemester)->create(['classroom_id' => $this->classroomsByNumber['01']->id]);
        $this->attend('ana', $oldSchedule, array_fill(0, 5, 'absent'), '2026-04-');
        $this->audit($this->admin, AuditLogger::ENROLLMENT_APPROVED, ['enrollment_id' => $old->id]);
    }

    private function enroll(string $key, string $name, string $dni, string $career, ?string $classroom, string $status, string $createdAt, ?string $decidedAt = null, ?string $code = null, ?string $rejectionReason = null, ?string $submittedAt = null): void
    {
        $this->students[$key] = User::factory()->student()->withCompletedProfile()->create(['name' => $name, 'dni' => $dni]);

        $this->enrollments[$key] = Enrollment::factory()->for($this->students[$key], 'student')->create([
            'semester_id' => $this->semester->id,
            'career_id' => $this->careersByCode[$career]->id,
            'classroom_id' => $classroom ? $this->classroomsByNumber[$classroom]->id : null,
            'group' => $this->careersByCode[$career]->group,
            'status' => $status,
            'enrollment_code' => $code,
            'created_at' => $createdAt,
            'submitted_at' => $submittedAt,
            'approved_at' => $status === 'approved' ? $decidedAt : null,
            'rejected_at' => $status === 'rejected' ? $submittedAt ?? $createdAt : null,
            'rejection_reason' => $rejectionReason,
        ]);
    }

    private function pay(string $student, string $key, float $amount, string $status, string $createdAt, ?string $reviewedAt = null, ?string $reason = null, bool $partial = false): void
    {
        $this->payments[$key] = Payment::factory()->for($this->students[$student], 'student')->for($this->enrollments[$student])->create([
            'amount' => $amount,
            'status' => $status,
            'partial' => $partial,
            'created_at' => $createdAt,
            'validated_at' => $reviewedAt,
            'rejection_reason' => $reason,
        ]);
    }

    private function attend(string $student, Schedule $schedule, array $statuses, string $datePrefix = '2026-09-'): void
    {
        foreach ($statuses as $i => $status) {
            Attendance::factory()->for($this->students[$student], 'student')->for($schedule)->create([
                'status' => $status,
                'date' => $datePrefix.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function examResult(string $exam, string $student, float $score, int $rank, int $rankInGroup): void
    {
        ExamResult::factory()->for($this->exams[$exam])->create([
            'student_id' => $this->students[$student]->id,
            'score' => $score,
            'group' => $this->careersByCode[match ($student) { 'rosa' => 'PSI', default => 'SIS' }]->group,
            'rank' => $rank,
            'rank_in_group' => $rankInGroup,
        ]);
    }

    private function audit(User $actor, string $action, array $metadata, ?string $reason = null): void
    {
        AuditLog::create(['admin_id' => $actor->id, 'user_id' => null, 'action' => $action, 'reason' => $reason, 'metadata' => $metadata]);
    }
}
