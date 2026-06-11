<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArbetsorderRad extends Model
{
    protected $table = 'arbetsorder_rader';

    protected $fillable = [
        'arbetsorder_id',
        'arbetsorder_nr',
        'rad_nr',
        'artikel',
        'artikel_normalized',
        'antal',
        'enhet',
        'raw_line',
        'kommentar',
    ];

    protected $casts = [
        'arbetsorder_id' => 'integer',
        'arbetsorder_nr' => 'integer',
        'rad_nr' => 'integer',
        'antal' => 'decimal:3',
    ];

    public function arbetsorder(): BelongsTo
    {
        return $this->belongsTo(Arbetsorder::class, 'arbetsorder_id');
    }

    public function leveransRader(): HasMany
    {
        return $this->hasMany(LeveransRad::class, 'arbetsorder_rad_id');
    }
}
