<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Support\ContentIndexes;
use Illuminate\Database\Seeder;

/**
 * Contenido público inicial en MongoDB: preguntas frecuentes y bloques del inicio,
 * con la información del CEPRE UNAJMA (cepreunajma.com).
 *
 * Los datos de contacto y pago salen de system_settings y las fechas, montos y
 * vacantes del ciclo activo (FoundationSeeder), para que coincidan con lo que
 * aplica el sistema. Se puede ejecutar varias veces: actualiza por pregunta y
 * por tipo de bloque. Requiere FoundationSeeder.
 */
class ContentSeeder extends Seeder
{
    /** Preguntas de versiones anteriores de este seeder, reemplazadas por las actuales. */
    public const OBSOLETE_QUESTIONS = [
        '¿Cómo me matriculo en el CEPRE?',
        '¿Qué documentos debo subir y en qué formato?',
        '¿Cómo registro mi pago?',
        '¿Puedo pagar en cuotas?',
    ];

    public function run(): void
    {
        ContentIndexes::ensure();

        Faq::whereIn('question', self::OBSOLETE_QUESTIONS)->delete();

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
        $cycle = Semester::current();
        $setting = fn (string $key) => SystemSetting::getValue($key);
        $phones = implode(' y ', (array) $setting('contact_phones'));

        return [
            // ── Matrícula ──
            [
                'category' => 'matricula',
                'question' => '¿Cómo me inscribo en el CEPRE?',
                'answer' => 'Ingresa con tu cuenta de Google, completa tu perfil con tu DNI y teléfono, y sigue los 4 pasos de la inscripción: verificar perfil, elegir carrera, subir documentos y confirmar. Solo puedes iniciar tu inscripción dentro del periodo de inscripción del ciclo.',
                'keywords' => ['inscripcion', 'matricula', 'pasos', 'como inscribirme'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Cuáles son los requisitos de inscripción?',
                'answer' => 'Recibo de pago (JPG, PNG o PDF), foto del postulante (JPG o PNG; es la foto de tu carnet), DNI (PDF) y certificado de estudios (PDF). Si aún no tienes el certificado, puedes inscribirte igual: tu matrícula queda con el certificado pendiente de regularizar hasta que lo entregues. Cada archivo puede pesar hasta 5 MB.',
                'keywords' => ['requisitos', 'documentos', 'dni', 'foto', 'certificado de estudios', 'recibo'],
            ],
            [
                'category' => 'matricula',
                'question' => $cycle ? "¿Cuándo es la inscripción del {$cycle->name}?" : '¿Cuándo es la inscripción?',
                'answer' => $cycle && $cycle->enrollment_start
                    ? "La inscripción es {$cycle->enrollmentPeriodLabel()}."
                        .($cycle->classes_start ? " Las clases van del {$cycle->classes_start->format('d/m/Y')} al {$cycle->classes_end?->format('d/m/Y')}." : '')
                    : 'Las fechas se publican en la página de inicio al abrir cada ciclo.',
                'keywords' => ['fechas', 'inscripcion', 'inicio de clases', 'cronograma'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Cuántas vacantes hay por carrera?',
                'answer' => $cycle && $cycle->careers()->exists()
                    ? "En el {$cycle->name}: ".$this->vacanciesText($cycle).'.'
                    : 'Las vacantes se publican al abrir cada ciclo.',
                'keywords' => ['vacantes', 'carreras', 'cupos'],
            ],
            [
                'category' => 'matricula',
                'question' => '¿Qué ciclos tiene el CEPRE?',
                'answer' => 'Cada año hay tres ciclos: Ciclo Intensivo, Ciclo Ordinario I y Ciclo Ordinario II. Cada ciclo es independiente: tienes que inscribirte y pagar en cada uno.',
                'keywords' => ['ciclos', 'intensivo', 'ordinario', 'calendario'],
            ],
            // ── Pagos ──
            [
                'category' => 'pagos',
                'question' => '¿Cuánto cuesta el ciclo?',
                'answer' => $cycle && $cycle->payment_plans
                    ? $this->plansText($cycle).' Eliges la modalidad con tu primer pago y no se puede cambiar después.'
                    : 'Los montos se publican al abrir cada ciclo.',
                'keywords' => ['costo', 'precio', 'cuotas', 'pago fraccionado', 'pago unico'],
            ],
            [
                'category' => 'pagos',
                'question' => '¿Dónde y cómo pago?',
                'answer' => "Pagas en la {$setting('payment_place')}. Luego registra tu pago en el sistema con el N° de tu recibo de caja y una foto o PDF del recibo. Cada recibo se registra una sola vez.",
                'keywords' => ['caja', 'donde pagar', 'recibo', 'voucher', 'unajma'],
            ],
            [
                'category' => 'pagos',
                'question' => '¿Me devuelven el dinero si me retiro?',
                'answer' => 'No. Los pagos realizados no tienen devolución.',
                'keywords' => ['devolucion', 'reembolso', 'retiro'],
            ],
            // ── Examen ──
            [
                'category' => 'examen',
                'question' => '¿Cómo se define quién ingresa a la universidad?',
                'answer' => 'Se ordena a los postulantes de cada carrera por su puntaje. Ingresan los mejores hasta cubrir las vacantes de la carrera, siempre que alcancen el puntaje mínimo del ciclo.',
                'keywords' => ['ingresantes', 'ingreso', 'puntaje minimo', 'ranking', 'vacantes'],
            ],
            [
                'category' => 'examen',
                'question' => '¿Las faltas pueden impedirme rendir los exámenes?',
                'answer' => 'Sí. Al acumular cierta cantidad de faltas pasas a estado "en riesgo" y, si llegas al máximo permitido, quedas inhabilitado. Cada ciclo empiezas de cero: las faltas de ciclos anteriores no cuentan.',
                'keywords' => ['faltas', 'asistencia', 'inhabilitado', 'en riesgo', 'examen'],
            ],
            // ── General ──
            [
                'category' => 'general',
                'question' => '¿Dónde queda la oficina del CEPRE y cuál es su horario?',
                'answer' => "La oficina está en {$setting('contact_office')}. Horario de atención: {$setting('contact_office_hours')}.",
                'keywords' => ['oficina', 'direccion', 'horario', 'atencion', 'ubicacion'],
            ],
            [
                'category' => 'general',
                'question' => '¿Cómo me comunico con el CEPRE?',
                'answer' => "Por teléfono al {$phones}, o por correo a {$setting('contact_email')}.",
                'keywords' => ['telefono', 'contacto', 'correo', 'whatsapp'],
            ],
        ];
    }

    /** @return list<array{type: string, data: array}> */
    private function homeBlocks(): array
    {
        $cycle = Semester::current();

        return [
            [
                'type' => 'banner',
                'data' => [
                    'title' => 'CEPRE UNAJMA',
                    'subtitle' => 'Centro Pre Universitario - Universidad Nacional José María Arguedas',
                    'text' => $cycle ? "Inscripciones abiertas para el {$cycle->name}." : 'Prepárate para tu ingreso a la universidad.',
                    'cta' => ['label' => 'Inscríbete', 'href' => '/login'],
                ],
            ],
            [
                'type' => 'fechas',
                'data' => [
                    'title' => $cycle ? "Fechas del {$cycle->name}" : 'Fechas importantes',
                    'items' => array_values(array_filter([
                        $cycle?->enrollment_start ? ['label' => 'Inscripción', 'date' => $cycle->enrollment_start->toDateString(), 'until' => $cycle->enrollment_end?->toDateString()] : null,
                        $cycle?->classes_start ? ['label' => 'Inicio de clases', 'date' => $cycle->classes_start->toDateString()] : null,
                        $cycle?->classes_end ? ['label' => 'Fin de clases', 'date' => $cycle->classes_end->toDateString()] : null,
                    ])),
                ],
            ],
            [
                'type' => 'carreras',
                'data' => [
                    'title' => 'Nuestras carreras',
                    'groups' => $this->careersByGroup($cycle),
                ],
            ],
            [
                'type' => 'cta',
                'data' => [
                    'title' => '¿Tienes dudas?',
                    'text' => 'Revisa las preguntas frecuentes sobre inscripción, pagos y exámenes.',
                    'cta' => ['label' => 'Ver preguntas frecuentes', 'href' => '/preguntas-frecuentes'],
                ],
            ],
        ];
    }

    /** Carreras activas agrupadas (A, B, C), con sus vacantes del ciclo activo. */
    private function careersByGroup(?Semester $cycle): array
    {
        $vacancies = $cycle ? $cycle->careers()->pluck('career_semester.vacancies', 'careers.id') : collect();
        $careers = Career::active()->orderBy('name')->get(['id', 'code', 'name', 'group'])->groupBy('group');

        return collect(Career::GROUPS)
            ->map(fn (string $label, string $group) => [
                'group' => $group,
                'label' => $label,
                'careers' => ($careers[$group] ?? collect())
                    ->map(fn (Career $c) => [...$c->only(['code', 'name']), 'vacancies' => $vacancies[$c->id] ?? null])
                    ->values()->all(),
            ])
            ->values()
            ->all();
    }

    /** "3 vacantes por carrera; Enfermería y Psicología, 2" */
    private function vacanciesText(Semester $cycle): string
    {
        // toBase(): agrupar una colección de Eloquent da grupos que no son modelos.
        $byCount = $cycle->careers()->orderBy('name')->get()->toBase()->groupBy(fn ($c) => $c->pivot->vacancies)->sortKeysDesc();
        $common = $byCount->sortByDesc(fn ($g) => $g->count())->keys()->first();

        $text = "{$common} vacantes por carrera";
        foreach ($byCount->except($common) as $count => $careers) {
            $names = $careers->pluck('name')->all();
            $last = array_pop($names);
            $text .= '; '.($names ? implode(', ', $names).' y ' : '').$last.", {$count}";
        }

        return $text;
    }

    /** "Pago único: S/ 600.00. Pago fraccionado: S/ 650.00 en 2 cuotas (S/ 500.00 y S/ 150.00)." */
    private function plansText(Semester $cycle): string
    {
        return collect($cycle->payment_plans)->map(function ($plan) {
            $total = array_sum($plan['installments']);
            $text = "{$plan['label']}: S/ ".number_format($total, 2);
            if (count($plan['installments']) > 1) {
                $amounts = array_map(fn ($a) => 'S/ '.number_format($a, 2), $plan['installments']);
                $last = array_pop($amounts);
                $text .= ' en '.(count($amounts) + 1).' cuotas ('.implode(', ', $amounts)." y {$last})";
            }

            return $text.'.';
        })->implode(' ');
    }
}
