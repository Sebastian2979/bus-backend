<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StopController extends Controller
{
    public function index(Request $request)
    {
        $line = $request->query('line');
        $direction = $request->query('direction', 0);

        $trip = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->where('trips.direction_id', $direction)
            ->whereNotNull('trips.shape_id')
            ->select('trip_id', 'shape_id')
            ->first();

        if (!$trip) {
            return response()->json([]);
        }

        $stops = DB::table('stop_times')
            ->where('trip_id', $trip->trip_id)
            ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
            ->orderBy('stop_times.stop_sequence')
            ->select(
                'stops.stop_id',
                'stops.stop_name',
                'stops.stop_lat',
                'stops.stop_lon'
            )
            ->get();

        return $stops->map(function ($stop) {
            return [
                'id' => $stop->stop_id,
                'name' => $stop->stop_name,
                'latitude' => (float) $stop->stop_lat,
                'longitude' => (float) $stop->stop_lon,
            ];
        });
    }

    public function shapeStops(string $shapeId)
    {
        $trip = DB::table('trips')
            ->where('shape_id', $shapeId)
            ->first();

        if (!$trip) {
            return response()->json([]);
        }

        $stops = DB::table('stop_times')
            ->join('stops', 'stops.stop_id', '=', 'stop_times.stop_id')
            ->where('stop_times.trip_id', $trip->trip_id)
            ->orderBy('stop_times.stop_sequence')
            ->select(
                'stops.stop_id as id',
                'stops.stop_name as name',
                'stops.stop_lat as latitude',
                'stops.stop_lon as longitude',
                'stop_times.stop_sequence'
            )
            ->get();

        return response()->json($stops);
    }
}
