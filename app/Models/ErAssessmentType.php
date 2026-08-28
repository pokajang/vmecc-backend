<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErAssessmentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'type_key',
        'label',
        'worst_case_scenario',
        'requirements',
        'icon_key',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
