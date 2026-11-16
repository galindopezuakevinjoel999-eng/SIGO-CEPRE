<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = Schedule::with(['course', 'teacher:id,name', 'career', 'classroom', 'semester']);

        // El docente solo ve sus propias clases.
        if ($request->user()->isDocente()) {
            $query->where('teacher_id', $request->user()->id);
        }

        foreach (['career_id', 'classroom_id', 'semester_id', 'teacher_id', 'course_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        return response()->json($query->orderBy('start_time')->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'course_id' => ['nullable', 'exists:courses,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'career_id' => ['nullable', 'exists:careers,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
            'days' => ['nullable', 'array'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ]);

        return response()->json(Schedule::create($data)->load(['course', 'career', 'classroom', 'semester']), 201);
    }

    public function show(Schedule $schedule)
    {
        return response()->json($schedule->load(['course', 'teacher:id,name', 'career', 'classroom', 'semester']));
    }

    public function update(Request $request, Schedule $schedule)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'course_id' => ['nullable', 'exists:courses,id'],
            'teacher_id' => ['nullable', 'exists:users,id'],
            'career_id' => ['nullable', 'exists:careers,id'],
            'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'semester_id' => ['nullable', 'exists:semesters,id'],
            'days' => ['nullable', 'array'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ]);

        $schedule->update($data);

        return response()->json($schedule);
    }

    public function destroy(Request $request, Schedule $schedule)
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $schedule->delete();

        return response()->json(['success' => true]);
    }
}
