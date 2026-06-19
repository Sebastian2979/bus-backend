<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TripController extends Controller
{
    public function byLine($line)
    {
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')

            // START HALTESTELLE (stop_sequence = 0)
            ->join('stop_times as st_start', function ($join) {
                $join->on('trips.trip_id', '=', 'st_start.trip_id')
                    ->where('st_start.stop_sequence', '=', 0);
            })

            // END HALTESTELLE (max stop_sequence pro trip)
            ->join('stop_times as st_end', function ($join) {
                $join->on('trips.trip_id', '=', 'st_end.trip_id')
                    ->whereRaw('st_end.stop_sequence = (
                     SELECT MAX(st2.stop_sequence)
                     FROM stop_times st2
                     WHERE st2.trip_id = trips.trip_id
                 )');
            })

            // STOP DETAILS
            ->join('stops as s_start', 'st_start.stop_id', '=', 's_start.stop_id')
            ->join('stops as s_end', 'st_end.stop_id', '=', 's_end.stop_id')

            ->where('routes.route_short_name', $line)

            ->select(
                'trips.trip_id',
                'trips.shape_id',
                'trips.direction_id',
                'trips.trip_headsign',

                // 🚍 neu: echte Richtung
                's_start.stop_name as start_name',
                's_end.stop_name as end_name'
            )

            ->get()

            // optional: Duplikate sauber reduzieren
            ->unique(function ($item) {
                return $item->start_name . '-' . $item->end_name;
            })
            ->values();

        return response()->json($trips);
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

    public function stopTimes($tripId)
    {
        Log::info("TripId", ['tripId' => $tripId]);
        return DB::table('stop_times')
            ->where('stop_times.trip_id', $tripId)
            ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
            ->orderBy('stop_times.stop_sequence')
            ->select(
                'stop_times.stop_sequence',
                'stop_times.arrival_time',
                'stop_times.departure_time',
                'stops.stop_name',
                'stops.stop_lat',
                'stops.stop_lon'
            )
            ->get();
    }

    public function upcomingDepartures($tripId)
    {
        $now = now();

        $nowSeconds = ($now->hour * 3600) + ($now->minute * 60) + $now->second;

        return DB::table('stop_times')
            ->where('trip_id', $tripId)
            ->where('stop_sequence', 0)
            ->select('trip_id', 'departure_time')
            ->orderBy('departure_time')
            ->get()
            ->filter(function ($row) use ($nowSeconds) {

                [$h, $m, $s] = array_pad(explode(':', $row->departure_time), 3, 0);
                $sec = ($h * 3600) + ($m * 60) + $s;

                return $sec >= $nowSeconds || $sec >= 86400;
            })
            ->take(5)
            ->values();
    }

    public function directions($line)
    {
        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->select('trips.trip_id')
            ->get();

        $directions = $trips->map(function ($trip) {
            $start = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('trip_id', $trip->trip_id)
                ->orderBy('stop_sequence')
                ->first();

            $end = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('trip_id', $trip->trip_id)
                ->orderByDesc('stop_sequence')
                ->first();

            return [
                'start' => $start?->stop_name,
                'end' => $end?->stop_name,
            ];
        });

        return $directions
            ->unique(fn($d) => $d['start'] . '|' . $d['end'])
            ->values();
    }

    public function departuresByDirection($line, $start, $end)
    {
        $nowSeconds = now()->hour * 3600
            + now()->minute * 60
            + now()->second;

        $trips = DB::table('trips')
            ->join('routes', 'trips.route_id', '=', 'routes.route_id')
            ->where('routes.route_short_name', $line)
            ->select('trips.trip_id')
            ->get();

        $departures = collect();

        foreach ($trips as $trip) {
            $firstStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('trip_id', $trip->trip_id)
                ->orderBy('stop_sequence')
                ->first();

            $lastStop = DB::table('stop_times')
                ->join('stops', 'stop_times.stop_id', '=', 'stops.stop_id')
                ->where('trip_id', $trip->trip_id)
                ->orderByDesc('stop_sequence')
                ->first();

            if (!$firstStop || !$lastStop) {
                continue;
            }

            if (
                $firstStop->stop_name === $start &&
                $lastStop->stop_name === $end
            ) {
                [$h, $m, $s] = explode(':', $firstStop->departure_time);
                $seconds = $h * 3600 + $m * 60 + $s;

                if ($seconds >= $nowSeconds) {
                    $departures->push([
                        'trip_id' => $trip->trip_id,
                        'departure_time' => $firstStop->departure_time,
                    ]);
                }
            }
        }

        return $departures
            ->sortBy('departure_time')
            ->take(5)
            ->values();
    }
}
