<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Archivos subidos por los usuarios en el disco privado (storage/app/private).
 * Nunca son accesibles por URL pública: se descargan con download(), desde
 * rutas autenticadas que ya pasaron por la policy correspondiente.
 */
class PrivateFiles
{
    public const DISK = 'private';

    /** Reglas de validación compartidas. Tamaños en KB, como los espera Laravel. */
    public const DOCUMENT_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'];

    public const MATERIAL_RULES = ['file', 'mimes:pdf,docx,pptx', 'extensions:pdf,docx,pptx', 'max:20480'];

    /**
     * Guarda el archivo con un nombre aleatorio dentro de $directory.
     *
     * @return array{path: string, name: string, mime: ?string, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        return [
            'path' => $file->store($directory, self::DISK),
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    /** Borra uno o varios archivos; ignora rutas vacías. */
    public function delete(string|array|null $paths): void
    {
        $paths = array_values(array_filter((array) $paths));

        if ($paths) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    /** Descarga con el nombre original del archivo. 404 si ya no existe en el disco. */
    public function download(?string $path, ?string $name = null): StreamedResponse
    {
        abort_unless($path && Storage::disk(self::DISK)->exists($path), 404, 'El archivo no existe.');

        return Storage::disk(self::DISK)->download($path, $name);
    }
}
