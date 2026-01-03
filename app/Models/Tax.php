<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tax extends Model
{
    public $timestamps = false;

    protected $table = 'taxes';

    protected $fillable = [
        'flight_class_id',
        'passenger_type',
        'tax_code',
        'tax_amount',
        'title_en',
        'title_fa',
    ];

    protected $casts = [
        'tax_amount' => 'decimal:2',
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
