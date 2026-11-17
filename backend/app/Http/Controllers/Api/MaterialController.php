<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Material\StoreMaterialRequest;
use App\Http\Requests\Material\UpdateMaterialRequest;
use App\Models\Material;
use App\Services\PrivateFiles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Materiales de estudio. Permisos en App\Policies\MaterialPolicy; los archivos
 * viven en el disco privado y se descargan con download().
 */
class MaterialController extends Controller
{
    public function __construct(private PrivateFiles $files) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Material::class);

        $query = Material::query()->with('course:id,code,name')->latest();

        if (Gate::denies('viewDrafts', Material::class)) {
            $query->where('status', 'published');
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->integer('course_id'));
        }

        return response()->json($query->paginate(20));
    }

    /** El docente sube un material; queda en borrador hasta que el admin lo publique. */
    public function store(StoreMaterialRequest $request)
    {
        $data = $request->safe()->except('file');
        $data['uploaded_by'] = $request->user()->id;

        if (Gate::denies('publish', Material::class)) {
            $data['status'] = 'draft';
        }

        $material = new Material($data);
        $this->attachFile($material, $request->file('file'));
        $material->save();

        return response()->json($material, 201);
    }

    public function show(Material $material)
    {
        Gate::authorize('view', $material);

        return response()->json($material->load('course:id,code,name'));
    }

    /** Descargar el archivo del material (RF29). */
    public function download(Material $material)
    {
        Gate::authorize('download', $material);

        return $this->files->download($material->file_path, $material->file_name);
    }

    public function update(UpdateMaterialRequest $request, Material $material)
    {
        $data = $request->safe()->except('file');

        if (Gate::denies('publish', Material::class)) {
            unset($data['status']);
        }

        $material->fill($data);

        $previous = null;
        if ($request->hasFile('file')) {
            $previous = $material->file_path;
            $this->attachFile($material, $request->file('file'));
        }

        $material->save();
        $this->files->delete($previous); // el anterior se borra después de guardar el nuevo

        return response()->json($material);
    }

    public function destroy(Material $material)
    {
        Gate::authorize('delete', $material);

        $material->delete(); // también borra el archivo del disco (Material::booted)

        return response()->json(['success' => true]);
    }

    private function attachFile(Material $material, UploadedFile $file): void
    {
        $stored = $this->files->store($file, 'materials');

        $material->file_path = $stored['path'];
        $material->file_name = $stored['name'];
        $material->file_size = $stored['size'];
        $material->type = strtolower($file->getClientOriginalExtension()); // pdf, docx o pptx
    }
}
