<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Career extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'group',
        'description',
        'status',
    ];

    /**
     * Obtener las carreras del grupo A (Ingeniería).
     */
    public function scopeGroupA($query)
    {
        return $query->where('group', 'A');
    }

    /**
     * Obtener las carreras del grupo B (Educación, Administración, Contabilidad, etc.).
     */
    public function scopeGroupB($query)
    {
        return $query->where('group', 'B');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
