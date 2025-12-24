<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightFareBreakdown extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_class_id',
        'passenger_type',
        'base_fare',
        'tax_i6',
        'tax_v0',
        'tax_hl',
        'tax_lp',
        'total_price',
        'fetched_at',
    ];

    protected $casts = [
        'base_fare' => 'decimal:2',
        'tax_i6' => 'decimal:2',
        'tax_v0' => 'decimal:2',
        'tax_hl' => 'decimal:2',
        'tax_lp' => 'decimal:2',
        'total_price' => 'decimal:2',
        'fetched_at' => 'datetime',
    ];

    public function flightClass()
    {
        return $this->belongsTo(FlightClass::class);
    }

    public function getTotalTaxesAttribute(): float
    {
        return $this->tax_i6 + $this->tax_v0 + $this->tax_hl + $this->tax_lp;
    }
}
