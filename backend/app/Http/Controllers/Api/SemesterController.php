<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SemesterRequest;
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

    public function store(SemesterRequest $request)
    {
        $activate = $request->boolean('is_active');
        $semester = Semester::create([...$request->semesterData(), 'is_active' => false]);

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

    public function update(SemesterRequest $request, Semester $semester)
    {
        $semester->update($request->semesterData());

        // Activar pasa por Semester::activate() (reinicia el ciclo); desactivar
        // (is_active = false) se guarda como un campo más.
        if ($request->has('is_active')) {
            $request->boolean('is_active') ? $semester->activate() : $semester->update(['is_active' => false]);
        }

        return response()->json($semester->refresh());
    }

    /**
     * Vacantes por carrera del ciclo: {"vacancies": [{"career_id": 1, "vacancies": 3}, ...]}.
     * Reemplaza las vacantes de las carreras enviadas; las demás no cambian.
     */
    public function vacancies(Request $request, Semester $semester)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Solo administración puede configurar vacantes.'], 403);
        }

        $data = $request->validate([
            'vacancies' => ['required', 'array', 'min:1'],
            'vacancies.*.career_id' => ['required', 'integer', 'distinct', 'exists:careers,id'],
            'vacancies.*.vacancies' => ['required', 'integer', 'between:0,1000'],
        ]);

        $semester->careers()->syncWithoutDetaching(
            collect($data['vacancies'])->mapWithKeys(fn ($v) => [$v['career_id'] => ['vacancies' => $v['vacancies']]])->all()
        );

        return response()->json($semester->careers()->orderBy('name')->get(['careers.id', 'code', 'name', 'group'])
            ->map(fn ($career) => [...$career->only(['id', 'code', 'name', 'group']), 'vacancies' => $career->pivot->vacancies]));
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
