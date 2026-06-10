<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomShift extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'start', 'end', 'sort_order'];
}
