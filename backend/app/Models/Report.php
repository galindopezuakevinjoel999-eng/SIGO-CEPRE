<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_type',
        'title',
        'generated_by',
        'filters',
        'data',
        'format',
        'file_url',
    ];

    protected $casts = [
        'filters' => 'array',
        'data' => 'array',
    ];
}
