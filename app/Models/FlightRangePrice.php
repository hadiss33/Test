<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightRangePrice extends Model
{
    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = [
        'origin',
        'destination',
        'min',
        'max',
    ];

    protected $casts = [
        'min' => 'decimal:2',
        'max' => 'decimal:2',
    ];
}
