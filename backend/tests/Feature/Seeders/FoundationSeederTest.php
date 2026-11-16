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

    public function test_semestre_2026_ii_como_unico_activo(): void
    {
        Semester::factory()->active()->create(['code' => '252', 'year' => 2025]);

        $this->seed(FoundationSeeder::class);

        $current = Semester::current();
        $this->assertSame('262', $current->code);
        $this->assertSame('2026-II', $current->name);
        $this->assertSame(1, Semester::where('is_active', true)->count());
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
        $this->assertNull(app(PaymentService::class)->enrollmentCost());
    }
}
