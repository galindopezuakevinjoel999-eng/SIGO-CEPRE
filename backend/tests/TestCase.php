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
}
