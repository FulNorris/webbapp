<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeveransRad extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'artikel',
        'article',
        'antal',
        'quantity',
        'sort_order',
        'work_order_number',
        'arbetsorder_nr',
        'arbetsorder_id',
        'arbetsorder_rad_id',
        'artikel_normalized',
        'enhet',
        'levererat_antal',
        'match_status',
        'match_warning',
    ];

    protected $casts = [
        'arbetsorder_nr' => 'integer',
        'arbetsorder_id' => 'integer',
        'arbetsorder_rad_id' => 'integer',
        'levererat_antal' => 'decimal:3',
    ];

    public function leverans(): BelongsTo
    {
        return $this->belongsTo(Leverans::class, 'order_id');
    }

    public function arbetsorder(): BelongsTo
    {
        return $this->belongsTo(Arbetsorder::class, 'arbetsorder_id');
    }

    public function arbetsorderRad(): BelongsTo
    {
        return $this->belongsTo(ArbetsorderRad::class, 'arbetsorder_rad_id');
    }
}
