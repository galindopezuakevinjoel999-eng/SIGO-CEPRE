<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Migración de estados de pago: completed→validated, failed/refunded→rejected. */
class PaymentStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require database_path('migrations/2026_11_16_000002_add_payment_review_fields.php');
    }

    private function insertPayment(int $studentId, string $status): int
    {
        return DB::table('payments')->insertGetId([
            'student_id' => $studentId,
            'amount' => 100,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_migra_los_estados_antiguos(): void
    {
        $this->migration->down(); // vuelve al esquema anterior
        $student = User::factory()->student()->create()->id;

        $ids = [
            'pending' => $this->insertPayment($student, 'pending'),
            'completed' => $this->insertPayment($student, 'completed'),
            'failed' => $this->insertPayment($student, 'failed'),
            'refunded' => $this->insertPayment($student, 'refunded'),
        ];

        $this->migration->up();

        $status = fn (string $old) => DB::table('payments')->find($ids[$old]);

        $this->assertSame('pending', $status('pending')->status);
        $this->assertSame('validated', $status('completed')->status);
        $this->assertSame('rejected', $status('failed')->status);
        $this->assertSame('rejected', $status('refunded')->status);
        $this->assertSame('Reembolsado', $status('refunded')->rejection_reason);
        $this->assertTrue(Schema::hasColumns('payments', ['validated_by', 'validated_at', 'rejection_reason']));
    }

    public function test_el_down_restaura_los_estados_antiguos(): void
    {
        $student = User::factory()->student()->create()->id;
        $validated = $this->insertPayment($student, 'validated');
        $rejected = $this->insertPayment($student, 'rejected');

        $this->migration->down();

        $this->assertSame('completed', DB::table('payments')->find($validated)->status);
        $this->assertSame('failed', DB::table('payments')->find($rejected)->status);
        $this->assertFalse(Schema::hasColumn('payments', 'validated_by'));

        $this->migration->up(); // deja el esquema como lo espera RefreshDatabase
    }

    public function test_el_esquema_nuevo_no_acepta_estados_antiguos(): void
    {
        $student = User::factory()->student()->create()->id;

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertPayment($student, 'completed');
    }
}
