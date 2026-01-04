<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightRule extends Model
{
    public $timestamps = false;

    protected $table = 'flight_rules';

    protected $fillable = [
        'flight_class_id',
        'rules',
        'penalty_percentage',
    ];

    protected $casts = [
        'penalty_percentage' => 'integer',
    ];

    /**
     * Relations
     */
    public function flightClass(): BelongsTo
    {
        return $this->belongsTo(FlightClass::class);
    }

    /**
     * Scopes
     */
    public function scopeOrderByPercent($query)
    {
        return $query->orderBy('penalty_percentage', 'asc');
    }
}