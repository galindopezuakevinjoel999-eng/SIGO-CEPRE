<?php

namespace Tests\Feature\Seeders;

use App\Models\Schedule;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Datos de prueba: solo con APP_ENV=local. */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedAsLocal(): void
    {
        $this->app['env'] = 'local';
        $this->seed(DatabaseSeeder::class);
    }

    public function test_crea_los_usuarios_de_prueba_por_rol(): void
    {
        $this->seedAsLocal();

        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->count());
        $this->assertSame(1, User::where('role', User::ROLE_COORDINADOR)->count());
        $this->assertSame(2, User::where('role', User::ROLE_DOCENTE)->count());
        $this->assertSame(3, User::where('role', User::ROLE_ESTUDIANTE)->count());
    }

    public function test_los_estudiantes_tienen_perfil_completo(): void
    {
        $this->seedAsLocal();

        $students = User::where('role', User::ROLE_ESTUDIANTE)->get();

        $this->assertTrue($students->every(fn (User $s) => $s->profile_completed && strlen($s->dni) === 8 && filled($s->phone)));
        $this->assertSame(3, $students->pluck('dni')->unique()->count());
    }

    public function test_el_personal_entra_con_la_contrasena_password(): void
    {
        $this->seedAsLocal();

        $this->postJson('/api/auth/login', ['email' => 'admin@cepre.edu.pe', 'password' => DemoSeeder::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.role', User::ROLE_ADMIN);
    }

    public function test_configura_el_costo_y_las_cuotas_de_prueba(): void
    {
        $this->seedAsLocal();

        $payments = app(PaymentService::class);
        $this->assertSame(350.0, $payments->enrollmentCost());
        $this->assertSame(2, $payments->installmentCount());
        $this->assertSame([['number' => 1, 'amount' => 175.0], ['number' => 2, 'amount' => 175.0]], $payments->installmentPlan());
    }

    public function test_horarios_sin_cruces_por_docente_ni_por_aula(): void
    {
        $this->seedAsLocal();

        $schedules = Schedule::with(['course', 'teacher', 'career', 'classroom'])->get();
        $this->assertCount(9, $schedules);
        $this->assertTrue($schedules->every(fn ($s) => $s->course && $s->teacher && $s->career && $s->classroom));

        foreach (['teacher_id', 'classroom_id'] as $resource) {
            foreach ($schedules as $a) {
                foreach ($schedules as $b) {
                    if ($a->is($b) || $a->{$resource} !== $b->{$resource} || ! array_intersect($a->days, $b->days)) {
                        continue;
                    }

                    $overlaps = $a->start_time < $b->end_time && $b->start_time < $a->end_time;
                    $this->assertFalse($overlaps, "Cruce de {$resource}: horarios {$a->id} y {$b->id}");
                }
            }
        }
    }

    public function test_se_puede_ejecutar_dos_veces_sin_duplicar(): void
    {
        $this->seedAsLocal();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(7, User::count());
        $this->assertSame(9, Schedule::count());
    }

    public function test_ejecutado_directamente_fuera_de_local_no_hace_nada(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(0, User::count());
    }
}
