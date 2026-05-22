<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

// =============================================================
// Calendar
// =============================================================
class Calendar extends Model
{
    protected $primaryKey = 'service_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'service_id',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday',
        'start_date', 'end_date',
    ];

    protected $casts = [
        'monday'    => 'boolean',
        'tuesday'   => 'boolean',
        'wednesday' => 'boolean',
        'thursday'  => 'boolean',
        'friday'    => 'boolean',
        'saturday'  => 'boolean',
        'sunday'    => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    /**
     * Prüft ob ein Service an einem bestimmten Datum aktiv ist (ohne calendar_dates).
     */
    public function isActiveOn(\Carbon\Carbon $date): bool
    {
        if ($date->lt($this->start_date) || $date->gt($this->end_date)) {
            return false;
        }

        $dayMap = [
            1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
            4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday',
        ];

        return (bool) $this->{$dayMap[$date->isoWeekday()]};
    }
}

// =============================================================
// CalendarDate
// =============================================================
class CalendarDate extends Model
{
    protected $fillable = ['service_id', 'date', 'exception_type'];

    protected $casts = [
        'date'           => 'date',
        'exception_type' => 'integer',
    ];

    public function scopeAdded(Builder $query): Builder
    {
        return $query->where('exception_type', 1);
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->where('exception_type', 2);
    }
}

// =============================================================
// Shape
// =============================================================
class Shape extends Model
{
    protected $fillable = [
        'shape_id',
        'shape_pt_lat',
        'shape_pt_lon',
        'shape_pt_sequence',
        'shape_dist_traveled',
    ];

    protected $casts = [
        'shape_pt_lat'       => 'float',
        'shape_pt_lon'       => 'float',
        'shape_pt_sequence'  => 'integer',
        'shape_dist_traveled' => 'float',
    ];

    public function scopeForShape(Builder $query, string $shapeId): Builder
    {
        return $query->where('shape_id', $shapeId)->orderBy('shape_pt_sequence');
    }
}

// =============================================================
// Trip
// =============================================================
class Trip extends Model
{
    protected $primaryKey = 'trip_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',
        'route_id',
        'service_id',
        'trip_headsign',
        'trip_short_name',
        'direction_id',
        'block_id',
        'shape_id',
        'wheelchair_accessible',
        'bikes_allowed',
    ];

    protected $casts = [
        'direction_id'          => 'integer',
        'wheelchair_accessible' => 'integer',
        'bikes_allowed'         => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id', 'route_id');
    }

    public function stopTimes(): HasMany
    {
        return $this->hasMany(StopTime::class, 'trip_id', 'trip_id')
                    ->orderBy('stop_sequence');
    }
}

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
