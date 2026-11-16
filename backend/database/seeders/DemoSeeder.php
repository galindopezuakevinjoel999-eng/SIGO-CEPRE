<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

/**
 * Datos de prueba para desarrollo. SOLO se ejecuta con APP_ENV=local.
 *
 * Todos los usuarios tienen la contraseña "password". Los estudiantes no pueden
 * iniciar sesión con contraseña (solo con Google); sus cuentas sirven para
 * probar matrículas y pagos.
 *
 * Requiere FoundationSeeder (carreras, aulas, semestre y cursos).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->warn('DemoSeeder omitido: solo se ejecuta con APP_ENV=local.');

            return;
        }

        $this->seedTestSettings();
        $teachers = $this->seedUsers();
        $this->seedSchedules($teachers);
    }

    /** Valores de prueba: el costo real lo define la administración. */
    private function seedTestSettings(): void
    {
        SystemSetting::setValue(PaymentService::COST_SETTING, '350', 'decimal', 'Costo total de la matrícula (S/) — valor de prueba');
        SystemSetting::setValue(PaymentService::INSTALLMENTS_SETTING, '2', 'integer', 'Número de cuotas en que se puede pagar la matrícula — valor de prueba');
    }

    /** @return array{math: User, science: User} */
    private function seedUsers(): array
    {
        $staff = [
            ['admin@cepre.edu.pe', 'Administrador CEPRE', User::ROLE_ADMIN],
            ['coordinador@cepre.edu.pe', 'Coordinación Académica CEPRE', User::ROLE_COORDINADOR],
            ['docente.matematica@cepre.edu.pe', 'Carlos Huamán Quispe', User::ROLE_DOCENTE],
            ['docente.ciencias@cepre.edu.pe', 'María Pacheco Rojas', User::ROLE_DOCENTE],
        ];

        $users = [];
        foreach ($staff as [$email, $name, $role]) {
            $users[$email] = $this->user($email, ['name' => $name, 'role' => $role]);
        }

        // Estudiantes con perfil completo (DNI y teléfono): pueden matricularse y pagar.
        $students = [
            ['luis.ccoica@estudiante.cepre.edu.pe', 'Luis Ccoica Huamaní', '71234561', '987654321'],
            ['rosa.palomino@estudiante.cepre.edu.pe', 'Rosa Palomino Gutiérrez', '72345672', '976543210'],
            ['jhon.vargas@estudiante.cepre.edu.pe', 'Jhon Vargas Cáceres', '73456783', '965432109'],
        ];

        foreach ($students as [$email, $name, $dni, $phone]) {
            $this->user($email, [
                'name' => $name,
                'role' => User::ROLE_ESTUDIANTE,
                'dni' => $dni,
                'phone' => $phone,
                'profile_completed' => true,
                'student_status' => 'activo',
            ]);
        }

        return [
            'math' => $users['docente.matematica@cepre.edu.pe'],
            'science' => $users['docente.ciencias@cepre.edu.pe'],
        ];
    }

    private function user(string $email, array $attributes): User
    {
        return User::updateOrCreate(['email' => $email], [
            'password' => self::PASSWORD, // el cast "hashed" del modelo la encripta
            'status' => 'active',
            ...$attributes,
        ]);
    }

    /**
     * Una semana tipo para tres carreras, una de cada grupo, sin cruces de
     * horario por docente ni por aula.
     *
     * @param  array{math: User, science: User}  $teachers
     */
    private function seedSchedules(array $teachers): void
    {
        $semester = Semester::where('code', FoundationSeeder::ACTIVE_SEMESTER_CODE)->firstOrFail();

        $week = [
            // [carrera, aula, curso, docente, días, inicio, fin]
            ['SIS', '01', 'RM', 'math', ['lunes', 'miercoles', 'viernes'], '08:00', '09:30'],
            ['SIS', '01', 'ALG', 'math', ['martes', 'jueves'], '08:00', '09:30'],
            ['SIS', '01', 'FIS', 'science', ['lunes', 'miercoles'], '09:45', '11:15'],
            ['SIS', '01', 'RV', 'science', ['martes', 'jueves'], '09:45', '11:15'],
            ['PSI', '03', 'BIO', 'science', ['lunes', 'miercoles', 'viernes'], '08:00', '09:30'],
            ['PSI', '03', 'QUI', 'science', ['martes', 'jueves'], '08:00', '09:30'],
            ['PSI', '03', 'RM', 'math', ['martes', 'jueves'], '09:45', '11:15'],
            ['ADM', '05', 'ARI', 'math', ['lunes', 'miercoles'], '11:30', '13:00'],
            ['ADM', '05', 'HIS', 'science', ['martes', 'jueves'], '11:30', '13:00'],
        ];

        foreach ($week as [$career, $classroom, $course, $teacher, $days, $start, $end]) {
            Schedule::updateOrCreate(
                [
                    'course_id' => Course::where('code', $course)->value('id'),
                    'career_id' => Career::where('code', $career)->value('id'),
                    'classroom_id' => Classroom::where('number', $classroom)->value('id'),
                    'semester_id' => $semester->id,
                    'start_time' => $start.':00',
                ],
                [
                    'teacher_id' => $teachers[$teacher]->id,
                    'days' => $days,
                    'end_time' => $end.':00',
                ],
            );
        }
    }
}
