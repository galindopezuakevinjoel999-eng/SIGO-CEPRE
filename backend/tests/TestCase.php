<?php

namespace Tests;

use App\Services\PrivateFiles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba escribe en storage/app/private real.
        Storage::fake(PrivateFiles::DISK);
    }

    /** Documento de matrícula o comprobante válido (PDF de 100 KB). */
    protected function fakePdf(string $name = 'documento.pdf', int $kilobytes = 100): File
    {
        return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
    }

    protected function privateDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(PrivateFiles::DISK);
    }

    /** Imagen JPG de prueba (sin GD: el contenido no importa para validar el tipo). */
    protected function fakeImage(string $name = 'foto.jpg', int $kilobytes = 200): File
    {
        return UploadedFile::fake()->create($name, $kilobytes, str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg');
    }

    /** Ciclo activo con la inscripción abierta hoy. */
    protected function openEnrollmentCycle(array $attributes = []): \App\Models\Semester
    {
        return \App\Models\Semester::factory()->active()->enrollmentOpen()->create($attributes);
    }

    /**
     * Documentos obligatorios del paso 3 (sin certificado de estudios).
     *
     * @return array<string, File>
     */
    protected function requiredDocuments(array $override = []): array
    {
        return [
            'recibo_pago' => $this->fakePdf('recibo.pdf'),
            'foto_postulante' => $this->fakeImage('foto.jpg'),
            'dni' => $this->fakePdf('dni.pdf'),
            ...$override,
        ];
    }

    /** N° de recibo de caja distinto en cada llamada. */
    protected function receiptNumber(): string
    {
        static $next = 0;

        return sprintf('RC-%06d', ++$next);
    }
}
