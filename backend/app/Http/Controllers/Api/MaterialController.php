<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Material;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isEstudiante()) {
            $hasActiveEnrollment = Enrollment::where('student_id', $user->id)
                ->where('status', 'approved')
                ->exists();

            if (! $hasActiveEnrollment) {
                return response()->json(['message' => 'Solo estudiantes con matrícula aprobada pueden ver materiales'], 403);
            }

            return response()->json(Material::where('status', 'published')->paginate(20));
        }

        if (! $user->isAdmin() && ! $user->isDocente() && ! $user->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(Material::paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isDocente()) {
            return response()->json(['message' => 'Solo admin y docentes pueden subir materiales'], 403);
        }

        $data = $request->validate([
            'course_id' => ['nullable', 'exists:courses,id'],
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $data['uploaded_by'] = $request->user()->id;

        if ($request->user()->isDocente()) {
            $data['status'] = 'draft';
        }

        return response()->json(Material::create($data), 201);
    }

    public function show(Request $request, Material $material)
    {
        if ($request->user()->isEstudiante() && $material->status !== 'published') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($material);
    }

    public function update(Request $request, Material $material)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isDocente()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($request->user()->isDocente() && $material->uploaded_by !== $request->user()->id) {
            return response()->json(['message' => 'Solo el docente autor puede modificar este material'], 403);
        }

        $data = $request->validate([
            'course_id' => ['nullable', 'exists:courses,id'],
            'title' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'file_url' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        if ($request->user()->isDocente()) {
            unset($data['status']);
        }

        $material->update($data);

        return response()->json($material);
    }

    public function destroy(Request $request, Material $material)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $material->delete();

        return response()->json(['success' => true]);
    }
}
