<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightBaggage extends Model
{
    public $timestamps = false;

    protected $table = 'flight_baggage';

    protected $fillable = [
        'flight_class_id',
        'adult_weight',
        'adult_pieces',
        'infant_weight',
        'infant_pieces',
        'child_weight',
        'child_pieces',
    ];

    /**
     * Relations
     */
    public function flightClass(): BelongsTo
    {
        return $this->belongsTo(FlightClass::class);
    }
}
