<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'quantity',
        'item_name',
        'store_name',
        'supplier',
        'store',
        'city',
        'sku',
        'product_url',
        'image_url',
        'thumbnail_path',
        'thumbnail_hash',
        'maps_label',
        'maps_url',
        'unit_price',
        'currency',
        'vat_rate',
        'total_net',
        'total_vat',
        'total_gross',
        'availability_at_selection',
        'selected_at',
        'fetched_at',
        'delivery_address',
        'recipient_name',
        'recipient_phone',
        'work_order_number',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_vat' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'selected_at' => 'datetime',
        'fetched_at' => 'datetime',
    ];
}
