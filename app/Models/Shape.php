<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

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