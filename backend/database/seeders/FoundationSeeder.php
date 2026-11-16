<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        // ── Carreras de ejemplo (el admin las configura desde la UI) ──
        $careers = [
            ['code' => 'ING', 'name' => 'Ingeniería de Sistemas', 'group' => 'A'],
            ['code' => 'EDU', 'name' => 'Educación', 'group' => 'B'],
            ['code' => 'ADM', 'name' => 'Administración de Empresas', 'group' => 'B'],
            ['code' => 'CON', 'name' => 'Contabilidad', 'group' => 'B'],
        ];

        foreach ($careers as $career) {
            Career::firstOrCreate(['code' => $career['code']], $career);
        }

        // ── Aulas de ejemplo ──
        for ($i = 1; $i <= 6; $i++) {
            Classroom::firstOrCreate(
                ['number' => str_pad($i, 2, '0', STR_PAD_LEFT)],
                ['name' => "Aula $i", 'capacity' => 30]
            );
        }

        // ── Semestre actual ──
        Semester::firstOrCreate(
            ['code' => '252'],
            [
                'year' => 2025,
                'cycle' => 2,
                'name' => '2025-II',
                'is_active' => true,
            ]
        );

        // ── Configuración del sistema ──
        $settings = [
            ['key' => 'max_absences', 'value' => '10', 'type' => 'integer', 'description' => 'Máximo de faltas antes de inhabilitación'],
            ['key' => 'absence_warning_1', 'value' => '5', 'type' => 'integer', 'description' => 'Primera alerta de faltas'],
            ['key' => 'absence_warning_2', 'value' => '8', 'type' => 'integer', 'description' => 'Segunda alerta de faltas (incluye coordinador)'],
            ['key' => 'institution_name', 'value' => 'CEPRE UNAJMA', 'type' => 'string', 'description' => 'Nombre de la institución'],
            ['key' => 'institution_full_name', 'value' => 'Centro Pre Universitario - Universidad Nacional José María Arguedas', 'type' => 'string', 'description' => 'Nombre completo de la institución'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(['key' => $setting['key']], $setting);
        }

        // ── Usuario admin de prueba (si no existe) ──
        User::firstOrCreate(
            ['email' => 'admin@cepre.edu.pe'],
            [
                'name' => 'Administrador CEPRE',
                'password' => Hash::make('admin123'),
                'role' => User::ROLE_ADMIN,
                'profile_completed' => true,
            ]
        );

        // ── Usuario coordinador de prueba ──
        User::firstOrCreate(
            ['email' => 'coordinador@cepre.edu.pe'],
            [
                'name' => 'Coordinador CEPRE',
                'password' => Hash::make('coord123'),
                'role' => User::ROLE_COORDINADOR,
                'profile_completed' => true,
            ]
        );

        // ── Usuario docente de prueba ──
        User::firstOrCreate(
            ['email' => 'docente@cepre.edu.pe'],
            [
                'name' => 'Docente CEPRE',
                'password' => Hash::make('docente123'),
                'role' => User::ROLE_DOCENTE,
                'profile_completed' => true,
            ]
        );
    }
}
