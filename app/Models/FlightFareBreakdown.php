<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightFareBreakdown extends Model
{
    protected $table = 'flight_fare_breakdown';

    public $timestamps = false;

    protected $fillable = [
        'flight_class_id',
        'total_adult',
        'total_child',
        'total_infant',
        'last_updated_at',
    ];

    protected $casts = [
        'total_adult' => 'decimal:2',
        'total_child' => 'decimal:2',
        'total_infant' => 'decimal:2',
        'last_updated_at' => 'datetime',
    ];

    public function flightClass()
    {
        return $this->belongsTo(FlightClass::class);
    }
}
