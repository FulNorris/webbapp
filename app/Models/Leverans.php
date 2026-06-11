<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Leverans extends Model
{
    protected $table = 'orders';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'adress',
        'tele',
        'mottagare',
        'desired_delivery_date',
        'desired_delivery_time',
        'notes',
        'status',
    ];

    protected $casts = [
        'desired_delivery_date' => 'date',
    ];

    public function rader(): HasMany
    {
        return $this->hasMany(LeveransRad::class, 'order_id');
    }
}
