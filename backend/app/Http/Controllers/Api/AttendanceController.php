<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            // Obligatorio: el horario define el semestre de la falta, y solo
            // cuentan las del semestre activo (User::refreshAttendanceStatus()).
            'schedule_id' => ['required', 'exists:schedules,id'],
            // Un registro por estudiante, horario y día (índice único en la tabla).
            // whereDate compara solo el día, en MySQL y en SQLite.
            'date' => ['bail', 'required', 'date', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                $exists = Attendance::where('student_id', $request->input('student_id'))
                    ->where('schedule_id', $request->input('schedule_id'))
                    ->whereDate('date', Carbon::parse($value)->toDateString())
                    ->exists();

                if ($exists) {
                    $fail('Ya se registró la asistencia de este estudiante en este horario y fecha.');
                }
            }],
            'status' => ['required', 'in:present,absent,late'],
            'remarks' => ['nullable', 'string'],
        ]);

        // Si se indica el horario, el curso se toma de él.
        if (empty($data['course_id'])) {
            $data['course_id'] = Schedule::find($data['schedule_id'])?->course_id;
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
            'schedule_id' => ['sometimes', 'exists:schedules,id'], // se puede cambiar, no vaciar
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

    /**
     * Los umbrales de faltas salen de system_settings (absence_warning_1 y
     * max_absences); la regla vive en User::refreshAttendanceStatus().
     */
    private function syncStudentAttendanceStatus(User $student): void
    {
        $student->refreshAttendanceStatus();
    }
}
