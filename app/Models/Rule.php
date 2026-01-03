<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rule extends Model
{
    public $timestamps = false;

    protected $table = 'rules';

    protected $fillable = [
        'flight_class_id',
        'refund_rules',
        'percent',
    ];

    protected $casts = [
        'percent' => 'integer',
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
        return $query->orderBy('percent', 'asc');
    }
}