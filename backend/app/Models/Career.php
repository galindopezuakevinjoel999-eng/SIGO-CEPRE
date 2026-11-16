<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Career extends Model
{
    use HasFactory;

    /** Grupos de carreras del CEPRE UNAJMA (primera letra del código de matrícula). */
    public const GROUPS = [
        'A' => 'Ingenierías',
        'B' => 'Salud',
        'C' => 'Ciencias de la empresa',
    ];

    protected $fillable = [
        'code',
        'name',
        'group',
        'description',
        'status',
    ];

    /**
     * Carreras de un grupo (ver GROUPS). Uso: Career::group('A')->get()
     */
    public function scopeGroup($query, string $group)
    {
        return $query->where('group', strtoupper($group));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
