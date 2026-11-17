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
            ['year' => 2026, 'cycle' => 2, 'name' => '2026-II'],
        );

        // Único activo. Si ya lo era, no reinicia las faltas del ciclo en curso.
        $semester->activate();

        return $semester;
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'max_absences', 'value' => '10', 'type' => 'integer', 'description' => 'Máximo de faltas antes de inhabilitación'],
            ['key' => 'absence_warning_1', 'value' => '5', 'type' => 'integer', 'description' => 'Primera alerta de faltas (pasa a "en riesgo")'],
            ['key' => 'absence_warning_2', 'value' => '8', 'type' => 'integer', 'description' => 'Segunda alerta de faltas (incluye coordinador)'],
            ['key' => 'institution_name', 'value' => 'CEPRE UNAJMA', 'type' => 'string', 'description' => 'Nombre de la institución'],
            ['key' => 'institution_full_name', 'value' => 'Centro Pre Universitario - Universidad Nacional José María Arguedas', 'type' => 'string', 'description' => 'Nombre completo de la institución'],
            // Sin valor fuera de local: el sistema no acepta pagos hasta que el
            // administrador configure el costo real. DemoSeeder pone valores de prueba.
            ['key' => 'enrollment_cost', 'value' => null, 'type' => 'decimal', 'description' => 'Costo total de la matrícula (S/)'],
            ['key' => 'enrollment_installments', 'value' => '1', 'type' => 'integer', 'description' => 'Número de cuotas en que se puede pagar la matrícula'],
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
