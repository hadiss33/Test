<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtraDetail extends Model
{
    protected $fillable = [
        'arrival_datetime',
    ];

    protected $casts = [
        'arrival_datetime' => 'datetime',
    ];
}