<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightTax extends Model
{
    public $timestamps = false;

    protected $table = 'flight_taxes';

    protected $fillable = [
        'flight_class_id',
        'passenger_type',
        'HL',
        'I6',
        'LP',
        'V0',
        'YQ',

    ];

    protected $casts = [
        'HL' => 'decimal:2',
        'I6' => 'decimal:2',
        'LP' => 'decimal:2',
        'V0' => 'decimal:2',
        'YQ' => 'decimal:2',

    ];

    public function flightClass(): BelongsTo
    {
        return $this->belongsTo(FlightClass::class);
    }

    public function scopeAdult($query)
    {
        return $query->where('passenger_type', 'adult');
    }

    public function scopeChild($query)
    {
        return $query->where('passenger_type', 'child');
    }

    public function scopeInfant($query)
    {
        return $query->where('passenger_type', 'infant');
    }
}
