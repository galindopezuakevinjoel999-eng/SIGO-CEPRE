<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Semester;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Catálogo base del CEPRE UNAJMA: carreras, aulas, semestre activo,
 * configuración y cursos. Corre en cualquier entorno y se puede ejecutar
 * varias veces sin duplicar datos.
 *
 * Los datos de prueba (usuarios, costo de matrícula, horarios) están en DemoSeeder.
 */
class FoundationSeeder extends Seeder
{
    public const ACTIVE_SEMESTER_CODE = '262';

    /** Modalidades de pago del Ordinario II 2026: único S/ 600 o fraccionado S/ 650 (500 + 150). */
    public const PAYMENT_PLANS = [
        'unico' => ['label' => 'Pago único', 'installments' => [600]],
        'fraccionado' => ['label' => 'Pago fraccionado', 'installments' => [500, 150]],
    ];

    public const DEFAULT_VACANCIES = 3;

    /** Carreras con vacantes distintas de DEFAULT_VACANCIES. */
    public const VACANCIES = ['PSI' => 2, 'ENF' => 2];

    /** [código, nombre, grupo]. Grupos: ver Career::GROUPS. */
    public const CAREERS = [
        // A — Ingenierías
        ['SIS', 'Ingeniería de Sistemas', 'A'],
        ['AMB', 'Ingeniería Ambiental', 'A'],
        ['AGR', 'Ingeniería Agroindustrial', 'A'],
        ['CIV', 'Ingeniería Civil', 'A'],
        // B — Salud
        ['PSI', 'Psicología', 'B'],
        ['ENF', 'Enfermería', 'B'],
        // C — Ciencias de la empresa
        ['ADM', 'Administración de Empresas', 'C'],
        ['EPI', 'Educación Primaria Intercultural', 'C'],
        ['CON', 'Contabilidad', 'C'],
        ['MEA', 'Matemáticas y Estadística Aplicadas', 'C'],
    ];

    /** Carreras de ejemplo de versiones anteriores del seeder. */
    public const LEGACY_CAREERS = ['ING', 'EDU'];

    /** [código, nombre] de los cursos comunes del ciclo CEPRE. */
    public const COURSES = [
        ['RV', 'Razonamiento Verbal'],
        ['RM', 'Razonamiento Matemático'],
        ['ARI', 'Aritmética'],
        ['ALG', 'Álgebra'],
        ['GEOM', 'Geometría'],
        ['TRI', 'Trigonometría'],
        ['FIS', 'Física'],
        ['QUI', 'Química'],
        ['BIO', 'Biología'],
        ['LEN', 'Lenguaje'],
        ['HIS', 'Historia'],
        ['GEOG', 'Geografía'],
    ];

    public function run(): void
    {
        $this->seedCareers();
        $this->seedClassrooms();
        $semester = $this->seedSemester();
        $this->seedVacancies($semester);
        $this->seedSettings();
        $this->seedCourses($semester);
    }

    private function seedCareers(): void
    {
        foreach (self::CAREERS as [$code, $name, $group]) {
            // updateOrCreate: corrige el grupo de carreras que ya existían (p. ej. ADM y CON eran B).
            Career::updateOrCreate(['code' => $code], ['name' => $name, 'group' => $group, 'status' => 'active']);
        }

        // Sus matrículas y cursos quedan con career_id en null (nullOnDelete).
        Career::whereIn('code', self::LEGACY_CAREERS)->delete();
    }

    private function seedClassrooms(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $number = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            Classroom::firstOrCreate(['number' => $number], ['name' => "Aula {$number}", 'capacity' => 40]);
        }
    }

    private function seedSemester(): Semester
    {
        $semester = Semester::updateOrCreate(
            ['code' => self::ACTIVE_SEMESTER_CODE],
            [
                'type' => 'ordinario_ii',
                'year' => 2026,
                'cycle' => Semester::TYPES['ordinario_ii']['cycle'],
                'name' => Semester::nameFor('ordinario_ii', 2026), // Ciclo Ordinario II 2026
                'enrollment_start' => '2026-08-03',
                'enrollment_end' => '2026-08-21',
                'classes_start' => '2026-09-07',
                'classes_end' => '2026-11-13',
                'payment_plans' => self::PAYMENT_PLANS,
            ],
        );

        // Único activo. Si ya lo era, no reinicia las faltas del ciclo en curso.
        $semester->activate();

        return $semester;
    }

    /** Vacantes del ciclo: 3 por carrera; Psicología y Enfermería, 2. */
    private function seedVacancies(Semester $semester): void
    {
        $vacancies = Career::whereIn('code', array_column(self::CAREERS, 0))->get()
            ->mapWithKeys(fn (Career $career) => [$career->id => ['vacancies' => self::VACANCIES[$career->code] ?? self::DEFAULT_VACANCIES]])
            ->all();

        $semester->careers()->syncWithoutDetaching($vacancies);
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'max_absences', 'value' => '10', 'type' => 'integer', 'description' => 'Máximo de faltas antes de inhabilitación'],
            ['key' => 'absence_warning_1', 'value' => '5', 'type' => 'integer', 'description' => 'Primera alerta de faltas (pasa a "en riesgo")'],
            ['key' => 'absence_warning_2', 'value' => '8', 'type' => 'integer', 'description' => 'Segunda alerta de faltas (incluye coordinador)'],
            ['key' => 'institution_name', 'value' => 'CEPRE UNAJMA', 'type' => 'string', 'description' => 'Nombre de la institución'],
            ['key' => 'institution_full_name', 'value' => 'Centro Pre Universitario - Universidad Nacional José María Arguedas', 'type' => 'string', 'description' => 'Nombre completo de la institución'],
            // Respaldo: los montos reales van en cada ciclo (semesters.payment_plans).
            // Solo se usan si un ciclo no tiene modalidades configuradas.
            ['key' => 'enrollment_cost', 'value' => null, 'type' => 'decimal', 'description' => 'Costo de matrícula de respaldo si el ciclo no tiene modalidades (S/)'],
            ['key' => 'enrollment_installments', 'value' => '1', 'type' => 'integer', 'description' => 'Cuotas del costo de respaldo'],
            // Atención y pagos (cepreunajma.com).
            ['key' => 'contact_office', 'value' => 'Jr. Sol Naciente, Totoral – San Jerónimo, a una cuadra del Óvalo del Niño', 'type' => 'string', 'description' => 'Oficina del CEPRE'],
            ['key' => 'contact_office_hours', 'value' => 'Lunes a viernes de 08:00 a 13:00 y de 14:00 a 16:30', 'type' => 'string', 'description' => 'Horario de atención'],
            ['key' => 'contact_phones', 'value' => json_encode(['912757826', '922803833']), 'type' => 'json', 'description' => 'Teléfonos de atención'],
            ['key' => 'contact_email', 'value' => 'cepre@unajma.edu.pe', 'type' => 'string', 'description' => 'Correo del CEPRE'],
            ['key' => 'payment_place', 'value' => 'Caja de la UNAJMA, Jr. Juan Francisco Ramos N° 380, Andahuaylas', 'type' => 'string', 'description' => 'Lugar de pago'],
            // Puntos por defecto de los exámenes (valor que se suma por respuesta; una
            // penalización va en negativo). Correcta e incorrecta: pendientes de definir
            // por el CEPRE; sin valor, cada examen debe indicarlos al crearse.
            ['key' => 'exam_points_correct', 'value' => null, 'type' => 'decimal', 'description' => 'Puntos por respuesta correcta (por defecto)'],
            ['key' => 'exam_points_wrong', 'value' => null, 'type' => 'decimal', 'description' => 'Puntos por respuesta incorrecta (por defecto; negativo si descuenta)'],
            ['key' => 'exam_points_blank', 'value' => '0', 'type' => 'decimal', 'description' => 'Puntos por respuesta en blanco (por defecto)'],
        ];

        // firstOrCreate: no pisa lo que el administrador ya configuró.
        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }
    }

    private function seedCourses(Semester $semester): void
    {
        foreach (self::COURSES as [$code, $name]) {
            // Cursos comunes a todas las carreras: sin career_id.
            Course::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'semester_id' => $semester->id, 'status' => 'active'],
            );
        }
    }
}
