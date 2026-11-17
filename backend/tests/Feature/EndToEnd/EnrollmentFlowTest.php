<?php

namespace Tests\Feature\EndToEnd;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Flujo completo de matrícula, solo a través de la API y con tokens reales de
 * Sanctum (sin actingAs):
 *
 *  login con Google → canje del código → completar perfil → matrícula pasos 1-4
 *  → pago 1 → validación → pago 2 → validación → aprobación con aula
 *  → código A-SIS-01-262-001, carnet y auditoría.
 *
 * Usa el catálogo real (FoundationSeeder): Ingeniería de Sistemas (grupo A),
 * aula 01 y semestre 2026-II (262). Costo de prueba: S/ 350 en 2 cuotas.
 */
class EnrollmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flujo_completo_de_matricula(): void
    {
        // ── Preparación ─────────────────────────────────────────────
        // Ciclo Ordinario II 2026 real: inscripción del 03/08 al 21/08/2026,
        // pago fraccionado S/ 650 en dos cuotas (500 + 150).
        $this->seed(FoundationSeeder::class);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-08-10 10:00:00', 'America/Lima'));

        $sistemas = Career::where('code', 'SIS')->firstOrFail();
        $aula01 = Classroom::where('number', '01')->firstOrFail();

        $admin = User::factory()->admin()->create();
        $adminToken = $admin->createToken('test')->plainTextToken;

        // ── 1. Login con Google (simulado) ──────────────────────────
        $this->fakeGoogle(id: 'g-ana', name: 'Ana Quispe Huamán', email: 'ana.quispe@gmail.com');

        $location = $this->get('/api/auth/google/callback')->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertArrayNotHasKey('token', $query);

        // ── 2. Canje del código por el token ────────────────────────
        $studentToken = $this->postJson('/api/auth/google/exchange', ['code' => $query['code']])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_ESTUDIANTE)
            ->json('token');
        $student = User::where('email', 'ana.quispe@gmail.com')->sole();

        // ── 3. Completar perfil ─────────────────────────────────────
        $this->as($studentToken)->getJson('/api/profile')->assertJsonPath('profile_completed', false);

        // Sin perfil completo no puede matricularse.
        $this->as($studentToken)->postJson('/api/enrollments', ['step' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'profile_incomplete');

        $this->as($studentToken)
            ->putJson('/api/profile', ['name' => 'Ana Quispe Huamán', 'dni' => '71234567', 'phone' => '987654321'])
            ->assertOk()
            ->assertJsonPath('data.profile_completed', true);

        // ── 4. Matrícula por pasos ──────────────────────────────────
        $enrollmentId = $this->as($studentToken)->postJson('/api/enrollments', ['step' => 1])
            ->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('semester.code', '262') // se asigna el semestre activo
            ->json('id');

        $this->as($studentToken)->putJson("/api/enrollments/{$enrollmentId}", ['step' => 2, 'career_id' => $sistemas->id])
            ->assertOk()->assertJsonPath('current_step', 2);

        // Sin certificado de estudios: queda pendiente de regularizar.
        $this->as($studentToken)->putJson("/api/enrollments/{$enrollmentId}", ['step' => 3, 'documents' => $this->requiredDocuments()])
            ->assertOk()
            ->assertJsonPath('current_step', 3)
            ->assertJsonPath('certificate_status', 'pending');

        $this->as($studentToken)->putJson("/api/enrollments/{$enrollmentId}", ['step' => 4])
            ->assertOk()
            ->assertJsonPath('current_step', 4)
            ->assertJsonPath('career.code', 'SIS');
        $this->assertNotNull(\App\Models\Enrollment::find($enrollmentId)->submitted_at, 'El paso 4 envía la matrícula a revisión');

        // ── 5. Pago 1 (cuota 1 de 2) ────────────────────────────────
        $payment1 = $this->as($studentToken)->postJson('/api/payments', [
            'enrollment_id' => $enrollmentId,
            'amount' => 500,
            'modality' => 'fraccionado',
            'payment_method' => 'caja',
            'receipt_number' => '001-004501',
            'receipt' => $this->fakePdf('recibo-cuota-1.pdf'),
        ])
            ->assertCreated()
            ->assertJsonPath('status', Payment::STATUS_PENDING)
            ->assertJsonPath('installment_number', 1)
            ->assertJsonPath('balance', 650)
            ->assertJsonPath('partial', true)
            ->json('id');

        // Sin un pago validado, todavía no se puede aprobar.
        $this->as($adminToken)->putJson("/api/enrollments/{$enrollmentId}/status", ['status' => 'approved', 'classroom_id' => $aula01->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede aprobar la matrícula: aún no tiene ningún pago validado.');

        // ── 6. El admin valida el pago 1 ────────────────────────────
        $this->as($adminToken)->putJson("/api/payments/{$payment1}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_VALIDATED)
            ->assertJsonPath('validated_by', $admin->id)
            ->assertJsonPath('balance', 150);

        // ── 7. Pago 2 (cuota 2 de 2) ────────────────────────────────
        $payment2 = $this->as($studentToken)->postJson('/api/payments', [
            'enrollment_id' => $enrollmentId,
            'amount' => 150,
            'payment_method' => 'caja',
            'receipt_number' => '001-004502',
            'receipt' => $this->fakePdf('recibo-cuota-2.pdf'),
        ])
            ->assertCreated()
            ->assertJsonPath('installment_number', 2)
            ->assertJsonPath('balance', 150)
            ->assertJsonPath('partial', false)
            ->json('id');

        // ── 8. El admin valida el pago 2 ────────────────────────────
        $this->as($adminToken)->putJson("/api/payments/{$payment2}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertOk()
            ->assertJsonPath('balance', 0);

        // Ya está pagada: no se aceptan más pagos.
        $this->as($studentToken)->postJson('/api/payments', ['enrollment_id' => $enrollmentId, 'amount' => 150, 'receipt_number' => '001-004503', 'receipt' => $this->fakePdf()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Esta matrícula ya está pagada por completo.']);

        // ── 9. El admin aprueba asignando el aula 01 ────────────────
        $this->as($adminToken)->putJson("/api/enrollments/{$enrollmentId}/status", ['status' => 'approved', 'classroom_id' => $aula01->id])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('enrollment_code', 'A-SIS-01-262-001')
            ->assertJsonPath('group', 'A')
            ->assertJsonPath('classroom.number', '01');

        // El estudiante ve su matrícula aprobada.
        $this->as($studentToken)->getJson("/api/enrollments/{$enrollmentId}")
            ->assertOk()
            ->assertJsonPath('enrollment_code', 'A-SIS-01-262-001');

        // ── 10. Carnet disponible ───────────────────────────────────
        $this->as($studentToken)->getJson("/api/enrollments/{$enrollmentId}/carnet/preview")
            ->assertOk()
            ->assertJsonPath('enrollment_code', 'A-SIS-01-262-001')
            ->assertJsonPath('student_name', 'Ana Quispe Huamán')
            ->assertJsonPath('career', 'Ingeniería de Sistemas')
            ->assertJsonPath('group', 'A')
            ->assertJsonPath('semester', 'Ciclo Ordinario II 2026')
            ->assertJsonPath('has_photo', true); // foto_postulante del paso 3

        $pdf = $this->as($studentToken)->get("/api/enrollments/{$enrollmentId}/carnet/download")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('carnet_A-SIS-01-262-001.pdf', $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // ── 11. Auditoría: dos validaciones y una aprobación ────────
        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(
            [AuditLogger::PAYMENT_VALIDATED, AuditLogger::PAYMENT_VALIDATED, AuditLogger::ENROLLMENT_APPROVED],
            $logs->pluck('action')->all(),
        );
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->admin_id === $admin->id && $log->user_id === $student->id));
        $this->assertSame([$payment1, $payment2], $logs->take(2)->pluck('metadata.payment_id')->all());
        $this->assertSame('A-SIS-01-262-001', $logs->last()->metadata['enrollment_code']);
        $this->assertSame('pending', $logs->last()->metadata['previous_status']);
    }

    /**
     * Headers con el token. El guard de Sanctum guarda al usuario en memoria
     * entre peticiones del mismo test; se reinicia para alternar entre
     * estudiante y admin como lo haría un cliente real.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function fakeGoogle(string $id, string $name, string $email): void
    {
        $googleUser = (new GoogleUser)
            ->setRaw(['email_verified' => true])
            ->map(['id' => $id, 'name' => $name, 'email' => $email, 'avatar' => null]);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}
