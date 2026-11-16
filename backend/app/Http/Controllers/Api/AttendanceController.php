<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\SystemNotification;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $query = Attendance::query();

        if ($request->user()->isEstudiante()) {
            $query->where('student_id', $request->user()->id);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isDocente()) {
            return response()->json(['message' => 'Solo docentes y administradores pueden registrar asistencias'], 403);
        }

        $data = $request->validate([
            'student_id' => ['required', 'exists:users,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'schedule_id' => ['nullable', 'exists:schedules,id'],
            'date' => ['required', 'date'],
            'status' => ['required', 'in:present,absent,late'],
            'remarks' => ['nullable', 'string'],
        ]);

        // Si se indica el horario, el curso se toma de él.
        if (! empty($data['schedule_id']) && empty($data['course_id'])) {
            $data['course_id'] = \App\Models\Schedule::find($data['schedule_id'])?->course_id;
        }
        $data['recorded_by'] = $request->user()->id;

        $attendance = Attendance::create($data);
        $this->syncStudentAttendanceStatus($attendance->student);

        return response()->json($attendance, 201);
    }

    public function show(Request $request, Attendance $attendance)
    {
        if (! $request->user()->isAdmin() && $attendance->student_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($attendance);
    }

    public function update(Request $request, Attendance $attendance)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isDocente()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'student_id' => ['nullable', 'exists:users,id'],
            'course_id' => ['nullable', 'exists:courses,id'],
            'schedule_id' => ['nullable', 'exists:schedules,id'],
            'date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:present,absent,late'],
            'remarks' => ['nullable', 'string'],
        ]);

        $attendance->update($data);
        $this->syncStudentAttendanceStatus($attendance->student);

        return response()->json($attendance);
    }

    public function destroy(Request $request, Attendance $attendance)
    {
        if (! $request->user()->isAdmin() && ! $request->user()->isDocente()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attendance->delete();

        $this->syncStudentAttendanceStatus($attendance->student);

        return response()->json(['success' => true]);
    }

    private function syncStudentAttendanceStatus($student): void
    {
        $absences = $student->attendances()->where('status', 'absent')->count();
        $previousStatus = $student->student_status;

        $student->absence_count = $absences;

        if ($absences >= 10) {
            $student->student_status = 'inhabilitado';
        } elseif ($absences >= 5) {
            $student->student_status = 'en_riesgo';
        } else {
            $student->student_status = 'activo';
        }

        $student->save();

        if ($previousStatus !== $student->student_status) {
            SystemNotification::create([
                'user_id' => $student->id,
                'type' => 'attendance_status',
                'title' => 'Tu estatus académico ha cambiado',
                'message' => "Tu estatus ha cambiado a {$student->student_status} por {$student->absence_count} faltas.",
            ]);
        }
    }
}
