<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

// =============================================================
// StopTime
// =============================================================
class StopTime extends Model
{
    public $timestamps = false; // Bewusst deaktiviert für Import-Performance

    protected $fillable = [
        'trip_id',
        'arrival_time',
        'departure_time',
        'stop_id',
        'stop_sequence',
        'stop_headsign',
        'pickup_type',
        'drop_off_type',
        'shape_dist_traveled',
        'timepoint',
    ];

    protected $casts = [
        'stop_sequence'      => 'integer',
        'pickup_type'        => 'integer',
        'drop_off_type'      => 'integer',
        'shape_dist_traveled' => 'float',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(Stop::class, 'stop_id', 'stop_id');
    }

    /**
     * Departure-Abfragen: Gibt Abfahrten ab einem Zeitpunkt zurück.
     * Hinweis: GTFS erlaubt Zeiten > 24:00:00 (z.B. 25:30:00 für Nachtfahrten).
     */
    public function scopeAfter(Builder $query, string $time): Builder
    {
        return $query->where('departure_time', '>=', $time);
    }
}