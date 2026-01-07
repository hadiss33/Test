<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

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
    }

    public function rules()
    {
        return $this->hasMany(FlightRule::class);
    }

    protected function taxSummary(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('taxes')) {
                    return [];
                }

                $taxColumns = ['HL', 'I6', 'LP', 'V0', 'YQ'];
                $taxesMap = $this->taxes->keyBy('passenger_type');

                $result = [];
                $passengerTypes = ['adult', 'child', 'infant'];

                foreach ($passengerTypes as $type) {
                    $record = $taxesMap->get($type);
                    if (! $record) {
                        continue;
                    }

                    $total = 0;
                    $details = [];

                    foreach ($taxColumns as $col) {
                        $amount = (float) $record->$col;
                        if ($amount > 0) {
                            $total += $amount;
                            $details[] = ['code' => $col, 'amount' => $amount];
                        }
                    }

                    $result[$type] = [
                        'details' => $details,
                        'total_amount' => $total,
                    ];
                }

                return $result;
            }
        );
    }


    protected function baggageFormatted(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('fareBaggage')) {
                    return [];
                }

                $baggage = $this->fareBaggage->first();
                if (! $baggage) {
                    return [];
                }

                return collect(['adult', 'child', 'infant'])->map(function ($type) use ($baggage) {
                    return [
                        'type' => $type,
                        'weight' => $baggage->{"{$type}_weight"} ?? 0,
                        'pieces' => $baggage->{"{$type}_pieces"} ?? 0,
                    ];
                });
            }
        );
    }

    protected function fareBreakdownFormatted(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->relationLoaded('fareBreakdown')) {
                    return [];
                }

                $breakdown = $this->fareBreakdown->first();
                if (! $breakdown) {
                    return [];
                }

                return collect(['adult', 'child', 'infant'])->map(function ($type) use ($breakdown) {
                    return [
                        'passenger_type' => $type,
                        'base_fare' => (float) ($breakdown->{"base_{$type}"} ?? 0),
                    ];
                });
            }
        );
    }
}
