<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'date',
        'year',
        'scope',
        'state',
        'is_default_national',
        'fixed_holiday_key',
    ];

    protected $casts = [
        'date'                => 'date:Y-m-d',
        'year'                => 'integer',
        'is_default_national' => 'boolean',
    ];

    public function histories(): HasMany
    {
        return $this->hasMany(HolidayHistory::class);
    }
}
