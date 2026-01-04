<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightFareBreakdown extends Model
{
    protected $table = 'flight_fare_breakdown';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;


    protected $fillable = [
        'flight_class_id',
        'base_adult',
        'base_child',
        'base_infant',
        'updated_at',
    ];

    protected $casts = [
        'base_adult' => 'decimal:2',
        'base_child' => 'decimal:2',
        'base_infant' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function flightClass()
    {
        return $this->belongsTo(FlightClass::class);
    }
}
