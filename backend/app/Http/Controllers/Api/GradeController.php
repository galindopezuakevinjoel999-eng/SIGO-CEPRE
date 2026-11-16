<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GradeController extends Controller
{
    public function index(Request $request)
    {
        $query = Grade::query();

        if (! $request->user()->isAdmin()) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'enrollment_id' => ['nullable', 'exists:enrollments,id'],
            'assessment' => ['nullable', 'string'],
            'score' => ['required', 'numeric', 'min:0', 'max:20'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        return response()->json(Grade::create($data), 201);
    }

    public function show(Request $request, Grade $grade)
    {
        if (! $request->user()->isAdmin() && $grade->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($grade);
    }

    public function update(Request $request, Grade $grade)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'student_id' => ['nullable', 'exists:users,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'enrollment_id' => ['nullable', 'exists:enrollments,id'],
            'assessment' => ['nullable', 'string'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ]);

        $grade->update($data);

        return response()->json($grade);
    }

    public function destroy(Request $request, Grade $grade)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $grade->delete();

        return response()->json(['success' => true]);
    }
}
