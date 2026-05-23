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