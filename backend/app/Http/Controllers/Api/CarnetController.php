<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class CarnetController extends Controller
{
    /**
     * Generar y descargar el carnet en PDF.
     */
    public function download(Request $request, Enrollment $enrollment)
    {
        $user = $request->user();

        // Solo el estudiante dueño o staff pueden descargar
        if ($user->isEstudiante() && $enrollment->student_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $enrollment->enrollment_code) {
            return response()->json(['message' => 'Esta matrícula aún no tiene código asignado'], 422);
        }

        $enrollment->load(['student', 'career', 'classroom', 'semester']);

        $html = $this->buildCarnetHtml($enrollment);

        $pdf = Pdf::loadHTML($html)
            ->setPaper([0, 0, 243, 153], 'landscape'); // Tamaño tipo carnet (85.6mm x 54mm aprox)

        $filename = "carnet_{$enrollment->enrollment_code}.pdf";

        return $pdf->download($filename);
    }

    /**
     * Vista previa del carnet (datos JSON).
     */
    public function preview(Request $request, Enrollment $enrollment)
    {
        $user = $request->user();

        if ($user->isEstudiante() && $enrollment->student_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $enrollment->load(['student', 'career', 'classroom', 'semester']);

        return response()->json([
            'enrollment_code' => $enrollment->enrollment_code,
            'student_name' => $enrollment->student->name,
            'student_photo' => $enrollment->student->photo_url,
            'career' => $enrollment->career?->name,
            'group' => $enrollment->group,
            'classroom' => $enrollment->classroom?->name,
            'semester' => $enrollment->semester?->name,
        ]);
    }

    /**
     * Construir el HTML del carnet.
     */
    private function buildCarnetHtml(Enrollment $enrollment): string
    {
        $code = $enrollment->enrollment_code;
        $name = $enrollment->student->name;
        $career = $enrollment->career?->name ?? 'N/A';
        $group = $enrollment->group ?? 'N/A';
        $classroom = $enrollment->classroom?->name ?? 'N/A';
        $semester = $enrollment->semester?->name ?? 'N/A';
        $photoUrl = $enrollment->student->photo_url;

        $photoHtml = $photoUrl
            ? "<img src=\"{$photoUrl}\" style=\"width:60px;height:75px;object-fit:cover;border:1px solid #ccc;border-radius:4px;\">"
            : '<div style="width:60px;height:75px;background:#e5e7eb;border:1px solid #ccc;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#9ca3af;">👤</div>';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: Arial, sans-serif; }
                .carnet {
                    width: 243px; height: 153px;
                    border: 2px solid #1e3a5f;
                    border-radius: 8px;
                    overflow: hidden;
                    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%);
                    color: white;
                }
                .header {
                    background: rgba(255,255,255,0.1);
                    padding: 4px 8px;
                    text-align: center;
                    border-bottom: 1px solid rgba(255,255,255,0.2);
                }
                .header h1 { font-size: 9px; letter-spacing: 1px; }
                .header p { font-size: 6px; opacity: 0.8; }
                .body {
                    display: flex;
                    padding: 6px 8px;
                    gap: 8px;
                }
                .photo { flex-shrink: 0; }
                .info { flex: 1; }
                .info .name { font-size: 8px; font-weight: bold; margin-bottom: 3px; }
                .info .detail { font-size: 6.5px; margin-bottom: 2px; opacity: 0.9; }
                .info .detail strong { color: #a3c4e9; }
                .code-bar {
                    background: rgba(255,255,255,0.15);
                    padding: 3px 8px;
                    text-align: center;
                    font-size: 8px;
                    font-weight: bold;
                    letter-spacing: 2px;
                    border-top: 1px solid rgba(255,255,255,0.2);
                }
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
                        <div class="detail"><strong>Semestre:</strong> {$semester}</div>
                    </div>
                </div>
                <div class="code-bar">{$code}</div>
            </div>
        </body>
        </html>
        HTML;
    }
}
