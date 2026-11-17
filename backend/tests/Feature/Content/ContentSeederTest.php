<?php

namespace Tests\Feature\Content;

use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use Database\Seeders\ContentSeeder;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

class ContentSeederTest extends TestCase
{
    use RefreshDatabase;
    use RefreshMongoDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FoundationSeeder::class);
        $this->seed(ContentSeeder::class);
    }

    public function test_crea_las_faqs_por_categoria(): void
    {
        $this->assertSame(12, Faq::count());
        $this->assertEquals(['matricula' => 5, 'pagos' => 3, 'examen' => 2, 'general' => 2], Faq::all()->countBy('category')->all());
        $this->assertTrue(Faq::all()->every(fn (Faq $faq) => $faq->active && $faq->keywords));
    }

    private function answer(string $question): string
    {
        return Faq::where('question', $question)->sole()->answer;
    }

    public function test_costos_y_lugar_de_pago_reales(): void
    {
        $this->assertSame(
            'Pago único: S/ 600.00. Pago fraccionado: S/ 650.00 en 2 cuotas (S/ 500.00 y S/ 150.00). Eliges la modalidad con tu primer pago y no se puede cambiar después.',
            $this->answer('¿Cuánto cuesta el ciclo?'),
        );
        $this->assertStringContainsString('Caja de la UNAJMA, Jr. Juan Francisco Ramos N° 380, Andahuaylas', $this->answer('¿Dónde y cómo pago?'));
        $this->assertSame('No. Los pagos realizados no tienen devolución.', $this->answer('¿Me devuelven el dinero si me retiro?'));
    }

    public function test_fechas_y_vacantes_del_ciclo_activo(): void
    {
        $this->assertSame(
            'La inscripción es del 03/08/2026 al 21/08/2026. Las clases van del 07/09/2026 al 13/11/2026.',
            $this->answer('¿Cuándo es la inscripción del Ciclo Ordinario II 2026?'),
        );
        $this->assertSame(
            'En el Ciclo Ordinario II 2026: 3 vacantes por carrera; Enfermería y Psicología, 2.',
            $this->answer('¿Cuántas vacantes hay por carrera?'),
        );
    }

    public function test_requisitos_y_contacto(): void
    {
        $requirements = $this->answer('¿Cuáles son los requisitos de inscripción?');
        foreach (['Recibo de pago', 'foto del postulante', 'DNI (PDF)', 'certificado de estudios (PDF)', 'pendiente de regularizar'] as $text) {
            $this->assertStringContainsString($text, $requirements);
        }

        $this->assertSame(
            'La oficina está en Jr. Sol Naciente, Totoral – San Jerónimo, a una cuadra del Óvalo del Niño. Horario de atención: Lunes a viernes de 08:00 a 13:00 y de 14:00 a 16:30.',
            $this->answer('¿Dónde queda la oficina del CEPRE y cuál es su horario?'),
        );
        $this->assertSame('Por teléfono al 912757826 y 922803833, o por correo a cepre@unajma.edu.pe.', $this->answer('¿Cómo me comunico con el CEPRE?'));
    }

    public function test_el_inicio_publico_incluye_el_contacto(): void
    {
        $this->getJson('/api/public/home')
            ->assertOk()
            ->assertJsonPath('contact.email', 'cepre@unajma.edu.pe')
            ->assertJsonPath('contact.phones', ['912757826', '922803833'])
            ->assertJsonPath('blocks.1.data.items.0', ['label' => 'Inscripción', 'date' => '2026-08-03', 'until' => '2026-08-21']);
    }

    public function test_reemplaza_las_preguntas_de_versiones_anteriores(): void
    {
        Faq::create(['question' => '¿Puedo pagar en cuotas?', 'answer' => 'Texto antiguo', 'category' => 'pagos']);
        Faq::create(['question' => '¿Hay becas?', 'answer' => 'Creada por el admin', 'category' => 'pagos']);

        $this->seed(ContentSeeder::class);

        $this->assertFalse(Faq::where('question', '¿Puedo pagar en cuotas?')->exists());
        $this->assertTrue(Faq::where('question', '¿Hay becas?')->exists(), 'No toca las FAQ del administrador');
    }

    public function test_crea_los_bloques_del_inicio_en_orden(): void
    {
        $this->assertSame(['banner', 'fechas', 'carreras', 'cta'], HomeBlock::orderBy('order')->pluck('type')->all());
    }

    public function test_el_bloque_de_carreras_usa_las_carreras_reales(): void
    {
        $groups = HomeBlock::where('type', 'carreras')->sole()->data['groups'];

        $this->assertSame(['A', 'B', 'C'], array_column($groups, 'group'));
        $this->assertCount(4, $groups[0]['careers']);
        $this->assertContains('Ingeniería de Sistemas', array_column($groups[0]['careers'], 'name'));
    }

    public function test_el_inicio_publico_muestra_el_contenido_sembrado(): void
    {
        $this->getJson('/api/public/home')->assertOk()->assertJsonCount(4, 'blocks');
        $this->getJson('/api/public/faqs?category=pagos')->assertOk()->assertJsonCount(3);
    }

    public function test_se_puede_ejecutar_dos_veces_sin_duplicar(): void
    {
        $faqs = Faq::count();

        $this->seed(ContentSeeder::class);

        $this->assertSame($faqs, Faq::count());
        $this->assertSame(4, HomeBlock::count());
    }
}
