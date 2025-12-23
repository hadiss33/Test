<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationInterface extends Model
{
    protected $table = 'application_interfaces';
    public $timestamps = false;

    protected $fillable = [
        'branch',
        'type',
        'service',
        'url',
        'username',
        'password',
        'data',
        'status'
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function getDataAttribute($value)
    {
        return json_decode($value, true);
    }

    public function setDataAttribute($value)
    {
        $this->attributes['data'] = json_encode($value);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByService($query, string $service)
    {
        return $query->where('service', $service);
    }
}