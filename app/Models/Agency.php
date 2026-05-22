<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    protected $primaryKey = 'agency_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'agency_id',
        'agency_name',
        'agency_url',
        'agency_timezone',
        'agency_lang',
        'agency_phone',
    ];

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'agency_id', 'agency_id');
    }
}
