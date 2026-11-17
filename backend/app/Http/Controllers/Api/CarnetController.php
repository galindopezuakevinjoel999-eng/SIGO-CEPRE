<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Services\PrivateFiles;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Carnet del estudiante matriculado. Permisos: los de ver la matrícula
 * (EnrollmentPolicy: el estudiante dueño, administración y coordinación).
 *
 * La foto es el documento "foto_postulante" del paso 3, leída del disco
 * privado e incrustada en el PDF (dompdf no descarga recursos remotos).
 */
class CarnetController extends Controller
{
    /**
     * Generar y descargar el carnet en PDF.
     */
    public function download(Enrollment $enrollment)
    {
        Gate::authorize('view', $enrollment);

        if (! $enrollment->enrollment_code) {
            return response()->json(['message' => 'Esta matrícula aún no tiene código asignado'], 422);
        }

        $enrollment->load(['student', 'career', 'classroom', 'semester']);

        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false])
            ->loadHTML($this->buildCarnetHtml($enrollment))
            ->setPaper([0, 0, 243, 153], 'landscape'); // Tamaño tipo carnet (85.6mm x 54mm aprox)

        return $pdf->download("carnet_{$enrollment->enrollment_code}.pdf");
    }

    /**
     * Vista previa del carnet (datos JSON).
     */
    public function preview(Enrollment $enrollment)
    {
        Gate::authorize('view', $enrollment);

        $enrollment->load(['student', 'career', 'classroom', 'semester']);

        return response()->json([
            'enrollment_code' => $enrollment->enrollment_code,
            'student_name' => $enrollment->student->name,
            'has_photo' => $enrollment->hasDocument('foto_postulante'),
            'career' => $enrollment->career?->name,
            'group' => $enrollment->group,
            'classroom' => $enrollment->classroom?->name,
            'semester' => $enrollment->semester?->name,
        ]);
    }

    /** Foto del postulante como data URI, o null si no la subió o ya no existe. */
    private function photoDataUri(Enrollment $enrollment): ?string
    {
        $photo = $enrollment->documentOfType('foto_postulante');
        $disk = Storage::disk(PrivateFiles::DISK);

        if (! $photo || ! $disk->exists($photo['path'])) {
            return null;
        }

        $mime = in_array($photo['mime'] ?? null, ['image/jpeg', 'image/png'], true) ? $photo['mime'] : 'image/jpeg';

        return "data:{$mime};base64,".base64_encode($disk->get($photo['path']));
    }

    /**
     * Construir el HTML del carnet. Todos los datos se escapan con e().
     */
    private function buildCarnetHtml(Enrollment $enrollment): string
    {
        $code = e($enrollment->enrollment_code);
        $name = e($enrollment->student->name);
        $career = e($enrollment->career?->name ?? 'N/A');
        $group = e($enrollment->group ?? 'N/A');
        $classroom = e($enrollment->classroom?->name ?? 'N/A');
        $semester = e($enrollment->semester?->name ?? 'N/A');
        $photo = $this->photoDataUri($enrollment);

        $photoHtml = $photo
            ? '<img src="'.e($photo).'" style="width:60px;height:75px;object-fit:cover;border:1px solid #ccc;border-radius:4px;">'
            : '<div style="width:60px;height:75px;background:#e5e7eb;border:1px solid #ccc;border-radius:4px;"></div>';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: DejaVu Sans, sans-serif; }
                .carnet { width: 243px; height: 153px; border: 2px solid #1e3a5f; border-radius: 8px; overflow: hidden; background: #1e3a5f; color: white; }
                .header { padding: 4px 8px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.2); }
                .header h1 { font-size: 9px; letter-spacing: 1px; }
                .header p { font-size: 6px; opacity: 0.8; }
                .body { padding: 6px 8px; }
                .photo { float: left; margin-right: 8px; }
                .info .name { font-size: 8px; font-weight: bold; margin-bottom: 3px; }
                .info .detail { font-size: 6.5px; margin-bottom: 2px; }
                .info .detail strong { color: #a3c4e9; }
                .code-bar { clear: both; padding: 3px 8px; text-align: center; font-size: 8px; font-weight: bold; letter-spacing: 2px; border-top: 1px solid rgba(255,255,255,0.2); }
            </style>
        </head>
        <body>
            <div class="carnet">
                <div class="header">
                    <h1>CEPRE UNAJMA</h1>
                    <p>Centro Pre Universitario</p>
                </div>
                <div class="body">
                    <div class="photo">{$photoHtml}</div>
                    <div class="info">
                        <div class="name">{$name}</div>
                        <div class="detail"><strong>Carrera:</strong> {$career}</div>
                        <div class="detail"><strong>Grupo:</strong> {$group}</div>
                        <div class="detail"><strong>Aula:</strong> {$classroom}</div>
                        <div class="detail"><strong>Ciclo:</strong> {$semester}</div>
                    </div>
                </div>
                <div class="code-bar">{$code}</div>
            </div>
        </body>
        </html>
        HTML;
    }
}
