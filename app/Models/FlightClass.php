<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlightClass extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_id',
        'class_code',
        'price_adult',
        'price_child',
        'price_infant',
        'available_seats',
        'status',
        'last_updated_at',
    ];

    protected $casts = [
        'price_adult' => 'decimal:2',
        'price_child' => 'decimal:2',
        'price_infant' => 'decimal:2',
        'last_updated_at' => 'datetime',
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
        return $this->hasMany(Tax::class);
    }

    public function fareBaggage()
    {
        return $this->hasMany(Baggage::class);
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
