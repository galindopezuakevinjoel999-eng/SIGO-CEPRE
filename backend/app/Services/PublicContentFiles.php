<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Archivos del contenido público (portadas de noticias, adjuntos de comunicados)
 * en el disco "public" (storage/app/public, servido en /storage tras
 * `php artisan storage:link`). Solo para contenido que cualquiera puede ver:
 * los documentos de estudiantes van en PrivateFiles.
 */
class PublicContentFiles
{
    public const DISK = 'public';

    public const IMAGE_RULES = ['file', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:2048'];

    public const ATTACHMENT_RULES = ['file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'];

    /** @return array{name: string, path: string, url: string} */
    public function store(UploadedFile $file, string $directory): array
    {
        $path = $file->store($directory, self::DISK);

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'url' => Storage::disk(self::DISK)->url($path),
        ];
    }

    public function delete(string|array|null $paths): void
    {
        $paths = array_values(array_filter((array) $paths));

        if ($paths) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }
}
