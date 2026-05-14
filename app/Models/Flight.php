<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;

    protected $fillable = [
        'iata',
        'airline_active_route_id',
        'flight_number',
        'departure_datetime',
        'missing_count',
        'updated_at',
        'api_request_at',
        'db_saved_at',
        'is_open',
        'flight_score',  // 0-100: اولویت‌بندی (NiraFlightScorer محاسبه می‌کند)
        'next_check_at', // timestamp: زمان بعدی check (NULL = بسته یا irrelevant)

    ];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'updated_at' => 'datetime',
        'is_open' => 'boolean',
        'flight_score' => 'integer',
        'next_check_at' => 'datetime',

    ];

    // ─── Relations ─────────────────────────────────────────────────

    public function route()
    {
        return $this->belongsTo(AirlineActiveRoute::class, 'airline_active_route_id');
    }

    public function activeRoute()
    {
        return $this->belongsTo(AirlineActiveRoute::class, 'airline_active_route_id');
    }

    public function classes()
    {
        return $this->hasMany(FlightClass::class);
    }

    public function details()
    {
        return $this->hasOne(FlightDetail::class);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    public function getFlightDateAttribute(): string
    {
        return $this->departure_datetime->toDateString();
    }

    public function getDaysUntilDeparture(): int
    {
        return now()->diffInDays($this->departure_datetime, false);
    }

    public function calculatePriority(): int
    {
        $days = $this->getDaysUntilDeparture();
        if ($days <= 3) {
            return 1;
        }
        if ($days <= 7) {
            return 2;
        }
        if ($days <= 30) {
            return 3;
        }

        return 4;
    }

    public function isMissing(): bool
    {
        return $this->missing_count > 0;
    }

    public function shouldBeDeleted(): bool
    {
        return $this->missing_count >= 2;
    }

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeUpcoming($query)
    {
        return $query->where('departure_datetime', '>=', now());
    }

    public function scopeOnDate($query, Carbon $date)
    {
        return $query->whereDate('departure_datetime', $date->toDateString());
    }

    public function scopeMissing($query)
    {
        return $query->where('missing_count', '>', 0);
    }

    public function scopeForAirline($query, string $iata)
    {
        return $query->where('iata', $iata);
    }

    public function scopeDueForNiraUpdate($query)
    {
        return $query
            ->whereHas('route.applicationInterface', fn ($q) => $q->where('service', 'nira')->where('status', 1)
            )
            ->where('is_open', true)
            ->where('departure_datetime', '>', now())
            ->where(function ($q) {
                $q->whereNull('next_check_at')
                    ->orWhere('next_check_at', '<=', now());
            });
    }

    // ─── Filters ───────────────────────────────────────────────────

    public const RELATION_MAP = [
        'Rule' => 'classes.rules',
        'FareBreakdown' => 'classes.fareBreakdown',
        'Tax' => 'classes.taxes',
        'TaxDetails' => 'classes.taxes',
        'Baggage' => 'classes.fareBaggage',
    ];

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        if (! empty($filters['datetime_start']) && ! empty($filters['datetime_end'])) {
            $query->whereDate('departure_datetime', '>=', $filters['datetime_start'])
                ->whereDate('departure_datetime', '<=', $filters['datetime_end']);
        } elseif (! empty($filters['datetime_start'])) {
            $query->whereDate('departure_datetime', '>=', $filters['datetime_start']);
        }

        if (! empty($filters['origin']) || ! empty($filters['destination']) || ! empty($filters['airline'])) {
            $query->whereHas('route', function ($q) use ($filters) {
                if (! empty($filters['origin'])) {
                    $q->where('origin', $filters['origin']);
                }
                if (! empty($filters['destination'])) {
                    $q->where('destination', $filters['destination']);
                }
                if (! empty($filters['airline'])) {
                    $q->where('iata', $filters['airline']);
                }
            });
        }

        if (! empty($filters['service'])) {
            $query->whereHas('route.applicationInterface', function ($q) use ($filters) {
                $q->where('service', $filters['service']);
            });
        }

        return $query;
    }
}
