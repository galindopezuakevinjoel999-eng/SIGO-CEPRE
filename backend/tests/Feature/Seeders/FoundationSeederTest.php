<?php

namespace Tests\Feature\Seeders;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Catálogo base del CEPRE UNAJMA (corre en cualquier entorno). */
class FoundationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_las_10_carreras_en_sus_3_grupos(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->assertSame(10, Career::count());
        $this->assertEqualsCanonicalizing(['SIS', 'AMB', 'AGR', 'CIV'], Career::group('A')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['PSI', 'ENF'], Career::group('B')->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['ADM', 'EPI', 'CON', 'MEA'], Career::group('C')->pluck('code')->all());
        $this->assertSame('Matemáticas y Estadística Aplicadas', Career::where('code', 'MEA')->value('name'));
    }

    public function test_elimina_las_carreras_de_ejemplo_y_corrige_grupos_antiguos(): void
    {
        // Datos que dejaba la versión anterior del seeder.
        Career::factory()->create(['code' => 'ING', 'group' => 'A']);
        Career::factory()->create(['code' => 'EDU', 'group' => 'B']);
        Career::factory()->create(['code' => 'ADM', 'group' => 'B']);

        $this->seed(FoundationSeeder::class);

        $this->assertFalse(Career::whereIn('code', ['ING', 'EDU'])->exists());
        $this->assertSame('C', Career::where('code', 'ADM')->value('group'));
    }

    public function test_ciclo_ordinario_ii_2026_como_unico_activo(): void
    {
        Semester::factory()->active()->create(['code' => '252', 'year' => 2025]);

        $this->seed(FoundationSeeder::class);

        $current = Semester::current();
        $this->assertSame('262', $current->code);
        $this->assertSame('ordinario_ii', $current->type);
        $this->assertSame('Ciclo Ordinario II 2026', $current->name);
        $this->assertSame(1, Semester::where('is_active', true)->count());
    }

    public function test_fechas_de_inscripcion_y_clases_del_ciclo(): void
    {
        $this->seed(FoundationSeeder::class);
        $cycle = Semester::current();

        $this->assertSame(['2026-08-03', '2026-08-21'], [$cycle->enrollment_start->toDateString(), $cycle->enrollment_end->toDateString()]);
        $this->assertSame(['2026-09-07', '2026-11-13'], [$cycle->classes_start->toDateString(), $cycle->classes_end->toDateString()]);
    }

    public function test_modalidades_de_pago_del_ciclo(): void
    {
        $this->seed(FoundationSeeder::class);

        $plans = app(PaymentService::class)->plansFor(Semester::current());

        $this->assertEquals(['label' => 'Pago único', 'installments' => [600], 'total' => 600], $plans['unico']);
        $this->assertEquals(['label' => 'Pago fraccionado', 'installments' => [500, 150], 'total' => 650], $plans['fraccionado']);
    }

    public function test_vacantes_por_carrera(): void
    {
        $this->seed(FoundationSeeder::class);

        $vacancies = Semester::current()->careers()->get()->mapWithKeys(fn ($c) => [$c->code => $c->pivot->vacancies])->all();

        $this->assertCount(10, $vacancies);
        $this->assertSame(2, $vacancies['PSI']);
        $this->assertSame(2, $vacancies['ENF']);
        $this->assertSame([3], array_values(array_unique(array_diff_key($vacancies, ['PSI' => 0, 'ENF' => 0]))));
    }

    public function test_datos_de_contacto_del_cepre(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->assertSame('cepre@unajma.edu.pe', SystemSetting::getValue('contact_email'));
        $this->assertSame(['912757826', '922803833'], SystemSetting::getValue('contact_phones'));
        $this->assertSame('Lunes a viernes de 08:00 a 13:00 y de 14:00 a 16:30', SystemSetting::getValue('contact_office_hours'));
        $this->assertStringContainsString('Jr. Sol Naciente', SystemSetting::getValue('contact_office'));
        $this->assertStringContainsString('Jr. Juan Francisco Ramos N° 380', SystemSetting::getValue('payment_place'));
    }

    public function test_aulas_cursos_y_configuracion(): void
    {
        $this->seed(FoundationSeeder::class);

        $this->assertSame(['01', '02', '03', '04', '05', '06'], Classroom::orderBy('number')->pluck('number')->all());

        $this->assertSame(12, Course::count());
        $this->assertTrue(Course::where('name', 'Razonamiento Matemático')->exists());
        $this->assertTrue(Course::whereNull('career_id')->count() === 12, 'Los cursos base son comunes a todas las carreras');

        $this->assertSame(10, SystemSetting::getValue('max_absences'));
        $this->assertSame(5, SystemSetting::getValue('absence_warning_1'));
    }

    public function test_se_puede_ejecutar_dos_veces_sin_duplicar(): void
    {
        $this->seed(FoundationSeeder::class);
        $this->seed(FoundationSeeder::class);

        $this->assertSame(10, Career::count());
        $this->assertSame(6, Classroom::count());
        $this->assertSame(12, Course::count());
        $this->assertSame(1, Semester::count());
    }

    public function test_no_pisa_la_configuracion_del_administrador(): void
    {
        SystemSetting::setValue('max_absences', '12', 'integer');

        $this->seed(FoundationSeeder::class);

        $this->assertSame(12, SystemSetting::getValue('max_absences'));
    }

    public function test_fuera_de_local_no_crea_usuarios_ni_costo_de_prueba(): void
    {
        $this->assertFalse(app()->isLocal());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        // El costo global de respaldo sigue vacío: los montos reales están en el ciclo.
        $this->assertNull(SystemSetting::where('key', PaymentService::COST_SETTING)->value('value'));
    }
}
