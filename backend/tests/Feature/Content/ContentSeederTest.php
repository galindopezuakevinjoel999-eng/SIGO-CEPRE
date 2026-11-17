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

    public function test_crea_entre_8_y_10_faqs_de_matricula_pagos_y_examen(): void
    {
        $this->assertGreaterThanOrEqual(8, Faq::count());
        $this->assertLessThanOrEqual(10, Faq::count());

        foreach (['matricula', 'pagos', 'examen'] as $category) {
            $this->assertTrue(Faq::where('category', $category)->exists(), "Falta una FAQ de {$category}");
        }

        $this->assertTrue(Faq::all()->every(fn (Faq $faq) => $faq->active && $faq->keywords));
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
