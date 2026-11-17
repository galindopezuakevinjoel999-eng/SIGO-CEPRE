<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Support\ContentIndexes;
use Illuminate\Database\Seeder;

/**
 * Contenido público inicial en MongoDB: preguntas frecuentes y bloques del inicio.
 *
 * Las FAQ describen cómo funciona SIGO-CEPRE (pasos, formatos, cuotas, faltas);
 * los montos y fechas oficiales los define el CEPRE y se editan desde el panel
 * (/api/admin/content). Las fechas del bloque "fechas" son de ejemplo.
 *
 * Se puede ejecutar varias veces: actualiza por pregunta y por tipo de bloque.
 * Requiere FoundationSeeder (carreras) para el bloque "carreras".
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        ContentIndexes::ensure();

        foreach ($this->faqs() as $order => $faq) {
            Faq::updateOrCreate(['question' => $faq['question']], [...$faq, 'order' => $order + 1, 'active' => true]);
        }

        foreach ($this->homeBlocks() as $order => $block) {
            HomeBlock::updateOrCreate(['type' => $block['type']], [...$block, 'order' => $order + 1, 'active' => true]);
        }
    }

    /** @return list<array{question: string, answer: string, category: string, keywords: list<string>}> */
    private function faqs(): array
    {
        return [
            // ── Matrícula ──
            [
                'category' => 'matricula',
                'question' => '¿Cómo me matriculo en el CEPRE?',
                'answer' => 'Ingresa con tu cuenta de Google, completa tu perfil con tu DNI y teléfono, y sigue los 4 pasos del formulario de matrícula: verificar perfil, elegir carrera, subir documentos y confirmar. Tu solicitud queda pendiente hasta que la administración la revise.',
                'keywords' => ['matricula', 'inscripcion', 'pasos', 'como matricularme'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Qué documentos debo subir y en qué formato?',
                'answer' => 'En el paso 3 subes tus documentos en PDF, JPG o PNG, de hasta 5 MB cada uno. Si te equivocas, puedes volver a subirlos mientras tu matrícula esté pendiente: los nuevos reemplazan a los anteriores.',
                'keywords' => ['documentos', 'dni', 'pdf', 'formato', 'subir archivos'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Puedo dejar la matrícula a medias y continuarla después?',
                'answer' => 'Sí. El sistema guarda tu avance en cada paso; cuando vuelvas a ingresar, continúas desde donde te quedaste.',
                'keywords' => ['guardar', 'avance', 'continuar', 'progreso'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Cuándo obtengo mi código de matrícula y mi carnet?',
                'answer' => 'Cuando la administración aprueba tu matrícula y te asigna un aula. Para aprobarla necesitas al menos un pago validado. Desde ese momento puedes ver y descargar tu carnet con tu código de matrícula.',
                'keywords' => ['codigo', 'carnet', 'aprobacion', 'aula'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿En qué grupo está mi carrera?',
                'answer' => 'Las carreras se organizan en tres grupos: A (Ingenierías), B (Salud) y C (Ciencias de la empresa). El grupo aparece al inicio de tu código de matrícula.',
                'keywords' => ['grupo', 'carrera', 'ingenierias', 'salud', 'ciencias de la empresa'],
            ],
            // ── Pagos ──
            [
                'category' => 'pagos',
                'question' => '¿Cómo registro mi pago?',
                'answer' => 'Desde tu matrícula, registra el monto y sube la foto o el PDF de tu comprobante (PDF, JPG o PNG, máximo 5 MB). El pago queda pendiente hasta que la administración lo valide.',
                'keywords' => ['pago', 'comprobante', 'voucher', 'registrar pago'],
            ],
            [
                'category' => 'pagos',
                'question' => '¿Puedo pagar en cuotas?',
                'answer' => 'Sí, según el número de cuotas que haya definido el CEPRE para el ciclo. El sistema te muestra el plan de cuotas y tu saldo pendiente, que se actualiza cada vez que se valida un pago.',
                'keywords' => ['cuotas', 'pago parcial', 'saldo', 'fraccionar'],
            ],
            [
                'category' => 'pagos',
                'question' => '¿Qué pasa si rechazan mi pago?',
                'answer' => 'Verás el motivo del rechazo en el detalle del pago. Ese monto no se descuenta de tu saldo; corrige el problema (por ejemplo, un comprobante ilegible) y registra el pago nuevamente.',
                'keywords' => ['rechazo', 'pago rechazado', 'motivo'],
            ],
            // ── Examen y asistencia ──
            [
                'category' => 'examen',
                'question' => '¿Las faltas pueden impedirme rendir los exámenes?',
                'answer' => 'Sí. Al acumular cierta cantidad de faltas pasas a estado "en riesgo" y, si llegas al máximo permitido, quedas inhabilitado. Los límites los fija el CEPRE. Cada semestre empiezas de cero: las faltas de ciclos anteriores no cuentan.',
                'keywords' => ['faltas', 'asistencia', 'inhabilitado', 'en riesgo', 'examen'],
            ],
            [
                'category' => 'horarios',
                'question' => '¿Dónde veo mi horario de clases?',
                'answer' => 'Cuando tu matrícula está aprobada, tu horario aparece en tu panel de estudiante según tu carrera y aula. Los materiales de cada curso también están disponibles ahí.',
                'keywords' => ['horario', 'clases', 'aula', 'materiales'],
            ],
        ];
    }

    /** @return list<array{type: string, data: array}> */
    private function homeBlocks(): array
    {
        return [
            [
                'type' => 'banner',
                'data' => [
                    'title' => 'CEPRE UNAJMA',
                    'subtitle' => 'Centro Pre Universitario - Universidad Nacional José María Arguedas',
                    'text' => 'Prepárate para tu ingreso a la universidad.',
                    'cta' => ['label' => 'Matricúlate', 'href' => '/login'],
                ],
            ],
            [
                // Fechas de ejemplo: el administrador las actualiza desde el panel.
                'type' => 'fechas',
                'data' => [
                    'title' => 'Fechas importantes',
                    'items' => [
                        ['label' => 'Inicio de matrícula', 'date' => '2026-08-03'],
                        ['label' => 'Inicio de clases', 'date' => '2026-08-24'],
                        ['label' => 'Primer examen', 'date' => '2026-10-04'],
                    ],
                ],
            ],
            [
                'type' => 'carreras',
                'data' => [
                    'title' => 'Nuestras carreras',
                    'groups' => $this->careersByGroup(),
                ],
            ],
            [
                'type' => 'cta',
                'data' => [
                    'title' => '¿Tienes dudas?',
                    'text' => 'Revisa las preguntas frecuentes sobre matrícula, pagos y exámenes.',
                    'cta' => ['label' => 'Ver preguntas frecuentes', 'href' => '/preguntas-frecuentes'],
                ],
            ],
        ];
    }

    /** Carreras activas agrupadas (A, B, C) desde la base SQL. */
    private function careersByGroup(): array
    {
        $careers = Career::active()->orderBy('name')->get(['code', 'name', 'group'])->groupBy('group');

        return collect(Career::GROUPS)
            ->map(fn (string $label, string $group) => [
                'group' => $group,
                'label' => $label,
                'careers' => ($careers[$group] ?? collect())->map->only(['code', 'name'])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
