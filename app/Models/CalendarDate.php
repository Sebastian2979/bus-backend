<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

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