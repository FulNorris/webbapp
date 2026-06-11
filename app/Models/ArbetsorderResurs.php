<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArbetsorderResurs extends Model
{
    protected $table = 'arbetsorder_resurser';

    protected $fillable = [
        'arbetsorder_id',
        'namn',
        'telefon',
    ];

    protected $casts = [
        'arbetsorder_id' => 'integer',
    ];

    public function arbetsorder(): BelongsTo
    {
        return $this->belongsTo(Arbetsorder::class, 'arbetsorder_id');
    }
}
