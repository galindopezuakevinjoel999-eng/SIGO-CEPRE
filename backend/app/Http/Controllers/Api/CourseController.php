<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        $query = Course::with(['career', 'semester']);

        if ($request->filled('career_id')) {
            $query->where('career_id', $request->integer('career_id'));
        }

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->integer('semester_id'));
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'unique:courses,code'],
            'name' => ['required', 'string'],
            'career_id' => ['nullable', 'exists:careers,id'],
            'description' => ['nullable', 'string'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed'])],
        ]);

        return response()->json(Course::create($data)->load(['career', 'semester']), 201);
    }

    public function update(Request $request, Course $course)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', Rule::unique('courses', 'code')->ignore($course->id)],
            'name' => ['required', 'string'],
            'career_id' => ['nullable', 'exists:careers,id'],
            'description' => ['nullable', 'string'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed'])],
        ]);

        $course->update($data);

        return response()->json($course->load(['career', 'semester']));
    }

    public function destroy(Course $course)
    {
        if (! request()->user()->isAdmin() && ! request()->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $course->delete();

        return response()->json(['success' => true]);
    }

    public function show(Course $course)
    {
        return response()->json($course->load(['career', 'semester']));
    }
}
