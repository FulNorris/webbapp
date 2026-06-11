<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Arbetsorder extends Model
{
    protected $table = 'arbetsordrar';

    protected $fillable = [
        'arbetsorder_nr',
        'utskriftsdatum',
        'handlaggare',
        'fardig_datum',
        'ordernr',
        'projekt',
        'startdatum',
        'startdatum_text',
        'kontaktperson',
        'telefon',
        'arbetsplats',
        'postadress',
        'arbetsbeskrivning_raw',
        'handskrivet_raw',
        'is_kopia',
        'raw_text',
    ];

    protected $casts = [
        'arbetsorder_nr' => 'integer',
        'utskriftsdatum' => 'date',
        'fardig_datum' => 'date',
        'ordernr' => 'integer',
        'projekt' => 'integer',
        'startdatum' => 'date',
        'is_kopia' => 'boolean',
    ];

    public function rader(): HasMany
    {
        return $this->hasMany(ArbetsorderRad::class, 'arbetsorder_id');
    }

    public function resurser(): HasMany
    {
        return $this->hasMany(ArbetsorderResurs::class, 'arbetsorder_id');
    }

    public function leveransRader(): HasMany
    {
        return $this->hasMany(LeveransRad::class, 'arbetsorder_id');
    }
}
