<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Stop extends Model
{
    protected $primaryKey = 'stop_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'stop_id',
        'stop_code',
        'stop_name',
        'stop_desc',
        'stop_lat',
        'stop_lon',
        'zone_id',
        'stop_url',
        'location_type',
        'parent_station',
        'platform_code',
    ];

    protected $casts = [
        'stop_lat'      => 'float',
        'stop_lon'      => 'float',
        'location_type' => 'integer',
    ];

    // -------------------------------------------------------
    // Relationships
    // -------------------------------------------------------

    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'stop_id', 'stop_id');
    }

    public function parentStation(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'parent_station', 'stop_id');
    }

    public function childStops(): HasMany
    {
        return $this->hasMany(Stop::class, 'parent_station', 'stop_id');
    }

    // -------------------------------------------------------
    // Scopes
    // -------------------------------------------------------

    /**
     * Umkreissuche via Haversine-Formel (in km).
     */
    public function scopeNearby(Builder $query, float $lat, float $lon, float $radiusKm = 0.5): Builder
    {
        $haversine = '(6371 * acos(
            cos(radians(?)) * cos(radians(stop_lat))
            * cos(radians(stop_lon) - radians(?))
            + sin(radians(?)) * sin(radians(stop_lat))
        ))';

        return $query
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lon, $lat])
            ->whereRaw("{$haversine} <= ?", [$lat, $lon, $lat, $radiusKm])
            ->orderBy('distance');
    }

    public function scopeStationsOnly(Builder $query): Builder
    {
        return $query->where('location_type', 1);
    }
}
