<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Roster extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'shift',
        'team_id',
        'status',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'date'         => 'date',
        'published_at' => 'datetime',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
