<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

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