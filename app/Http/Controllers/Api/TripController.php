<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
    // Alle Trips einer Linie (für UI Expand)
    public function byLine($line)
    {
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->select(
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign'
            )
            ->get();

        // dedupe by direction + headsign + shape
        return $trips->unique(function ($item) {
            return $item->direction_id . '-' . $item->trip_headsign . '-' . $item->shape_id;
        })->values();
    }

    // Optional: gruppiert für UI
    public function grouped()
    {
        return DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->select(
                'routes.route_short_name',
                'trips.trip_id',
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign'
            )
            ->get()
            ->groupBy('route_short_name');
    }
}
