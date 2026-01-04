<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightClass extends Model
{

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = [
        'flight_id',
        'class_code',
        'payable_adult',
        'payable_child',
        'payable_infant',
        'available_seats',
        'status',
        'updated_at',
    ];

    protected $casts = [
        'payable_adult' => 'decimal:2',
        'payable_child' => 'decimal:2',
        'payable_infant' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function flight()
    {
        return $this->belongsTo(Flight::class);
    }

    public function fareBreakdown()
    {
        return $this->hasMany(FlightFareBreakdown::class);
    }

    public function taxes()
    {
        return $this->hasMany(FlightTax::class);
    }

    public function fareBaggage()
    {
        return $this->hasMany(FlightBaggage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
            ->where('available_seats', '>', 0);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'active' && $this->available_seats > 0;
    }}
