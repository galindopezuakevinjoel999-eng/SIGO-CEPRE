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
        $query = Course::query();

        if ($request->filled('career')) {
            $query->where('career', $request->input('career'));
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
            'career' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'semester' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed'])],
        ]);

        return response()->json(Course::create($data), 201);
    }

    public function update(Request $request, Course $course)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isCoordinador()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', Rule::unique('courses', 'code')->ignore($course->id)],
            'name' => ['required', 'string'],
            'career' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'credits' => ['nullable', 'integer', 'min:0'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'semester' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'completed'])],
        ]);

        $course->update($data);

        return response()->json($course);
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
        return response()->json($course);
    }
}
