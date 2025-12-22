<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApplicationInterface extends Model
{
    protected $table = 'application_interface';
    public $timestamps = false;

    protected $fillable = [
        'branch',
        'type',
        'service',
        'object_type',
        'object',
        'url',
        'username',
        'password',
        'data',
        'priority',
        'status'
    ];

    protected $casts = [
        'data' => 'array',
        'status' => 'integer',
    ];

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