<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Grade;
use App\Models\Attendance;
use App\Models\SystemNotification;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_ESTUDIANTE = 'estudiante';
    public const ROLE_DOCENTE = 'docente';
    public const ROLE_COORDINADOR = 'coordinador';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'dni',
        'phone',
        'photo_url',
        'google_id',
        'profile_completed',
        'status',
        'absence_count',
        'student_status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'profile_completed' => 'boolean',
        'absence_count' => 'integer',
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEstudiante(): bool
    {
        return $this->role === self::ROLE_ESTUDIANTE;
    }

    public function isDocente(): bool
    {
        return $this->role === self::ROLE_DOCENTE;
    }

    public function isCoordinador(): bool
    {
        return $this->role === self::ROLE_COORDINADOR;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_DOCENTE, self::ROLE_COORDINADOR], true);
    }

    public function isActiveStudent(): bool
    {
        return $this->isEstudiante() && $this->student_status === 'activo';
    }

    public function getProfilePercentageAttribute(): int
    {
        $attributes = [
            'name' => $this->name,
            'email' => $this->email,
            'dni' => $this->dni,
            'phone' => $this->phone,
            'photo_url' => $this->photo_url,
        ];

        $filled = collect($attributes)->filter(fn ($value) => ! empty($value))->count();
        return (int) round(($filled / count($attributes)) * 100);
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'student_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'student_id');
    }

    public function notifications()
    {
        return $this->hasMany(SystemNotification::class, 'user_id');
    }

    public function refreshAttendanceStatus(): void
    {
        $absences = $this->attendances()->where('status', 'absent')->count();
        $previousStatus = $this->student_status;

        $this->absence_count = $absences;

        if ($absences >= 10) {
            $this->student_status = 'inhabilitado';
        } elseif ($absences >= 5) {
            $this->student_status = 'en_riesgo';
        } else {
            $this->student_status = 'activo';
        }

        $this->save();

        if ($previousStatus !== $this->student_status) {
            SystemNotification::create([
                'user_id' => $this->id,
                'type' => 'attendance_status',
                'title' => 'Cambios en tu estatus académico',
                'message' => "Tu estatus ha cambiado a {$this->student_status} por {$this->absence_count} faltas.",
            ]);
        }
    }
}
