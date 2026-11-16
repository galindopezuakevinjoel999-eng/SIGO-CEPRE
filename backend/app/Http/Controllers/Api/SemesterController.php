<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Semester;
use Illuminate\Http\Request;

class SemesterController extends Controller
{
    public function index()
    {
        return response()->json(Semester::orderBy('year', 'desc')->orderBy('cycle', 'desc')->get());
    }

    /**
     * Semestre activo actual (público).
     */
    public function current()
    {
        $semester = Semester::current();
        if (! $semester) {
            return response()->json(['message' => 'No hay semestre activo'], 404);
        }
        return response()->json($semester);
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'unique:semesters,code'],
            'year' => ['required', 'integer', 'min:2020'],
            'cycle' => ['required', 'integer', 'in:1,2'],
            'name' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $activate = ! empty($data['is_active']);
        $semester = Semester::create([...$data, 'is_active' => false]);

        // Desactiva los demás y reinicia las faltas de los estudiantes.
        if ($activate) {
            $semester->activate();
        }

        return response()->json($semester, 201);
    }

    public function show(Semester $semester)
    {
        return response()->json($semester);
    }

    public function update(Request $request, Semester $semester)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10', 'unique:semesters,code,'.$semester->id],
            'year' => ['nullable', 'integer', 'min:2020'],
            'cycle' => ['nullable', 'integer', 'in:1,2'],
            'name' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Activar pasa por Semester::activate() (reinicia el ciclo); desactivar
        // (is_active = false) se guarda como un campo más.
        $activate = ! empty($data['is_active']);
        if ($activate) {
            unset($data['is_active']);
        }

        $semester->update($data);

        if ($activate) {
            $semester->activate();
        }

        return response()->json($semester->refresh());
    }

    public function destroy(Semester $semester)
    {
        if (! request()->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $semester->delete();
        return response()->json(['success' => true]);
    }
}
