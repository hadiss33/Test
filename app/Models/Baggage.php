<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Baggage extends Model
{
    public $timestamps = false;

    protected $table = 'baggage';

    protected $fillable = [
        'flight_class_id',
        'baggage_weight',
        'baggage_pieces',
    ];

    /**
     * Relations
     */
    public function flightClass(): BelongsTo
    {
        return $this->belongsTo(FlightClass::class);
    }
}
