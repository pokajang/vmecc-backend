<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DrillEnvironmentOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'value',
        'title',
        'description',
        'icon_key',
    ];
}
