<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// =============================================================
// Route
// =============================================================
class Route extends Model
{
    protected $primaryKey = 'route_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'route_id',
        'agency_id',
        'route_short_name',
        'route_long_name',
        'route_desc',
        'route_type',
        'route_url',
        'route_color',
        'route_text_color',
    ];

    protected $casts = [
        'route_type' => 'integer',
    ];

    /**
     * GTFS route_type labels für die API-Response.
     */
    public function getRouteTypeNameAttribute(): string
    {
        return match ($this->route_type) {
            0 => 'Tram',
            1 => 'Metro',
            2 => 'Rail',
            3 => 'Bus',
            4 => 'Ferry',
            5 => 'Cable Car',
            6 => 'Gondola',
            7 => 'Funicular',
            default => 'Unknown',
        };
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id', 'route_id');
    }
}
